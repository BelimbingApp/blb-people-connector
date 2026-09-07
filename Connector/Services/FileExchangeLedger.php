<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The record every file passes through before any parser sees it (#263;
 * docs/providers/hr2000-file-exchange.md "Immutable exchange record").
 *
 * The ledger hashes the bytes at the file's path itself. A caller's declared
 * SHA-256 is a claim about the bytes, and a claim that disagrees with the
 * bytes is refused rather than recorded: an approval attached to a hash must
 * be an approval of exactly the bytes that hash names.
 *
 * The same bytes under one connection and direction are one record. The
 * unique key decides who wrote first; a second arrival is handed the first
 * row and writes nothing, not even an audit row, because nothing happened.
 */
final class FileExchangeLedger
{
    /**
     * A status reason is copied into the audit summary, so its bound is the
     * audit's: a reason the ledger accepts is one the audit accepts too.
     */
    private const MAX_REASON = OperatorAuditLog::MAX_STRING;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function record(
        ProviderConnection $connection,
        ProviderFile $file,
        string $direction,
        string $operation,
        Actor $actor,
        ?string $schemaVersion = null,
        ?string $evidenceReference = null,
        ?\DateTimeInterface $recordedAt = null,
    ): FileExchangeRecord {
        $tenantId = $this->tenantContext->requireTenantId();

        if (! in_array($direction, FileExchangeRecord::DIRECTIONS, true)) {
            throw new FileExchangeException("A file exchange direction is import or export, not [{$direction}].");
        }

        $operation = trim($operation);

        if ($operation === '' || strlen($operation) > 80) {
            throw new FileExchangeException('A file exchange record names its operation in at most 80 characters.');
        }

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new FileExchangeException('Recording a file requires an actor inside the current tenant.');
        }

        // The caller's connection object is not trusted for tenancy: the row is
        // re-read inside the ambient tenant, so a connection owned by another
        // tenant is simply not found here.
        $owned = ProviderConnection::query()
            ->forTenant($tenantId)
            ->whereKey((int) $connection->id)
            ->first();

        if ($owned === null || (int) $owned->tenant_id !== $tenantId) {
            throw new FileExchangeException('The provider connection is outside the current tenant.');
        }

        if ($owned->status === ProviderConnection::STATUS_RETIRED) {
            throw new FileExchangeException('A retired connection receives and produces no files.');
        }

        $sha256 = $this->hash($file);
        $byteLength = (int) filesize($file->path);

        $existing = $this->existing((int) $owned->id, $direction, $sha256);

        if ($existing !== null) {
            return $existing;
        }

        $recordedAt = $recordedAt === null
            ? \DateTimeImmutable::createFromInterface(now())
            : \DateTimeImmutable::createFromInterface($recordedAt);

        $attributes = [
            'tenant_id' => $tenantId,
            'company_id' => $owned->company_id === null ? null : (int) $owned->company_id,
            'provider_connection_id' => (int) $owned->id,
            'direction' => $direction,
            'operation' => $operation,
            'file_name' => $file->name,
            'sha256' => $sha256,
            'byte_length' => $byteLength,
            'schema_version' => $schemaVersion === null ? null : trim($schemaVersion),
            'evidence_reference' => $evidenceReference === null ? null : trim($evidenceReference),
            'recorded_at' => $recordedAt,
            'actor_user_id' => $actor->isUser() ? $actor->id : null,
            'status' => FileExchangeRecord::STATUS_RECORDED,
            'status_reason' => null,
        ];

        // The row and its audit land together or not at all. The insert runs
        // in its own (savepoint) transaction inside that: a unique violation
        // poisons the PostgreSQL transaction it happens in, so it must be one
        // that is rolled back and nothing else.
        return DB::transaction(function () use ($attributes, $owned, $direction, $sha256, $actor, $evidenceReference, $recordedAt): FileExchangeRecord {
            try {
                $record = DB::transaction(fn (): FileExchangeRecord => FileExchangeRecord::query()->create($attributes));
            } catch (UniqueConstraintViolationException) {
                return $this->existing((int) $owned->id, $direction, $sha256)
                    ?? throw new FileExchangeException('The file exchange record was written concurrently and could not be read back.');
            }

            $this->audit->record(
                $actor,
                OperatorAuditOperation::FileExchangeRecorded,
                (int) $owned->id,
                null,
                $evidenceReference,
                [],
                $this->summary($record),
                $recordedAt,
            );

            return $record;
        });
    }

    public function quarantine(FileExchangeRecord $record, string $reason, Actor $actor): FileExchangeRecord
    {
        return $this->transition($record, FileExchangeRecord::STATUS_QUARANTINED, $this->reason($reason), $actor);
    }

    public function archive(FileExchangeRecord $record, Actor $actor): FileExchangeRecord
    {
        return $this->transition($record, FileExchangeRecord::STATUS_ARCHIVED, null, $actor);
    }

    private function transition(FileExchangeRecord $record, string $status, ?string $reason, Actor $actor): FileExchangeRecord
    {
        $tenantId = $this->tenantContext->requireTenantId();

        if ((int) $record->tenant_id !== $tenantId) {
            throw new FileExchangeException('The file exchange record is outside the current tenant.');
        }

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new FileExchangeException('Changing a file exchange status requires an actor inside the current tenant.');
        }

        if ($record->status === FileExchangeRecord::STATUS_ARCHIVED) {
            throw new FileExchangeException('An archived file exchange record is final.');
        }

        $before = ['status' => $record->status, 'status_reason' => $record->status_reason];

        // The status and its audit row land together or not at all: an audit
        // refusal inside the transaction rolls the status back.
        DB::transaction(function () use ($record, $status, $reason, $actor, $before): void {
            $record->forceFill(['status' => $status, 'status_reason' => $reason])->save();

            $this->audit->record(
                $actor,
                OperatorAuditOperation::FileExchangeRecorded,
                (int) $record->provider_connection_id,
                null,
                $record->evidence_reference,
                $before,
                $this->summary($record),
            );
        });

        return $record;
    }

    private function existing(int $connectionId, string $direction, string $sha256): ?FileExchangeRecord
    {
        return FileExchangeRecord::query()
            ->where('provider_connection_id', $connectionId)
            ->where('direction', $direction)
            ->where('sha256', $sha256)
            ->first();
    }

    /** The lowercase SHA-256 of the bytes on disk; a declared hash that disagrees is a refusal. */
    private function hash(ProviderFile $file): string
    {
        if (! is_file($file->path) || ! is_readable($file->path)) {
            throw new FileExchangeException("The provider file [{$file->name}] is not readable, so its bytes cannot be recorded.");
        }

        $actual = hash_file('sha256', $file->path);

        if ($actual === false) {
            throw new FileExchangeException("The provider file [{$file->name}] could not be hashed.");
        }

        if (! hash_equals($actual, strtolower($file->sha256))) {
            throw new FileExchangeException("The provider file [{$file->name}] declares a SHA-256 that is not the hash of its bytes; nothing was recorded.");
        }

        return $actual;
    }

    /**
     * A status reason is a short line an operator reads back, never a path,
     * a payload or a stack trace.
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || strlen($reason) > self::MAX_REASON || preg_match('/[\r\n]|[\\\\\/]/', $reason) === 1) {
            throw new FileExchangeException('A quarantine reason is one short line without paths or line breaks.');
        }

        return $reason;
    }

    /**
     * What the audit row carries: names, hash, size and status. The path is
     * where the bytes live, not what they are, and it never enters the audit.
     *
     * @return array<string, scalar|null>
     */
    private function summary(FileExchangeRecord $record): array
    {
        return [
            'record_id' => (int) $record->id,
            'direction' => $record->direction,
            'operation' => $record->operation,
            'file_name' => $record->file_name,
            'sha256' => $record->sha256,
            'byte_length' => (int) $record->byte_length,
            'schema_version' => $record->schema_version,
            'status' => $record->status,
            'status_reason' => $record->status_reason,
        ];
    }
}
