<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * Operator-facing list / quarantine / archive over the file-exchange ledger (#285).
 *
 * The ledger already owns status transitions and their audit rows. This service
 * is the capability and tenancy gate in front of those writes, and the only
 * surface that lists a connection's ledger without exposing paths or bytes.
 */
final class FileExchangeOperator
{
    public const LIST_CAPABILITY = 'people-connector.connection.list';

    public const MANAGE_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly ProviderConnectionStore $connections,
        private readonly FileExchangeLedger $ledger,
    ) {}

    /**
     * Rows for one connection's ledger. Never includes paths, bytes or evidence.
     *
     * @return list<array{
     *     id: int,
     *     file_name: string,
     *     direction: string,
     *     operation: string,
     *     sha256_prefix: string,
     *     byte_length: int,
     *     schema_version: ?string,
     *     status: string,
     *     recorded_at: string
     * }>
     */
    public function list(Actor $actor, int $connectionId, ?string $status = null, ?\DateTimeImmutable $since = null): array
    {
        $tenantId = $this->authorize($actor, self::LIST_CAPABILITY, 'list');
        $connection = $this->connection($connectionId);

        $query = FileExchangeRecord::query()
            ->forTenant($tenantId)
            ->where('provider_connection_id', (int) $connection->id)
            ->orderBy('id');

        if ($status !== null && $status !== '') {
            if (! in_array($status, FileExchangeRecord::STATUSES, true)) {
                throw new FileExchangeException('A file exchange status is recorded, quarantined or archived.');
            }
            $query->where('status', $status);
        }

        if ($since !== null) {
            $query->where('recorded_at', '>=', $since);
        }

        return $query->get()->map(static fn (FileExchangeRecord $record): array => [
            'id' => (int) $record->id,
            'file_name' => (string) $record->file_name,
            'direction' => (string) $record->direction,
            'operation' => (string) $record->operation,
            'sha256_prefix' => substr((string) $record->sha256, 0, 12),
            'byte_length' => (int) $record->byte_length,
            'schema_version' => $record->schema_version === null ? null : (string) $record->schema_version,
            'status' => (string) $record->status,
            'recorded_at' => $record->recorded_at->format(DATE_ATOM),
        ])->all();
    }

    public function quarantine(Actor $actor, int $recordId, string $reason): FileExchangeRecord
    {
        $this->authorize($actor, self::MANAGE_CAPABILITY, 'quarantine');
        $record = $this->record($recordId);

        return $this->ledger->quarantine($record, $reason, $actor);
    }

    public function archive(Actor $actor, int $recordId): FileExchangeRecord
    {
        $this->authorize($actor, self::MANAGE_CAPABILITY, 'archive');
        $record = $this->record($recordId);

        return $this->ledger->archive($record, $actor);
    }

    private function authorize(Actor $actor, string $capability, string $operation): int
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorization->authorize($actor, $capability);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                'connector',
                $operation,
                'File exchange operator commands require an operator inside the current tenant.',
            );
        }

        return $tenantId;
    }

    private function connection(int $connectionId): ProviderConnection
    {
        return $this->connections->find($connectionId);
    }

    private function record(int $recordId): FileExchangeRecord
    {
        $tenantId = $this->tenants->requireTenantId();
        $record = FileExchangeRecord::query()->forTenant($tenantId)->whereKey($recordId)->first();

        if ($record === null) {
            throw new ConnectorRecordNotFoundException('The file exchange record was not found in the current tenant.');
        }

        // Re-resolve the connection so a foreign-tenant id fails the same way
        // list does, before the ledger is asked to change anything.
        $this->connection((int) $record->provider_connection_id);

        return $record;
    }
}
