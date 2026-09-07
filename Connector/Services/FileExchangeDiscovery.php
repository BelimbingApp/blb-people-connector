<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\FileExchangeDiscoveryReport;
use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\FileExchangeException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\FileExchangeRecord;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * Finds the files a scheduled export dropped for one connection and records
 * each in the exchange ledger by SHA-256 (#297). The directory is
 * `people-connector.file_exchange.inbound_root` plus the connection id, never
 * an operator-supplied path; a file that resolves outside the root is refused.
 * Nothing is moved, parsed beyond the header line, or projected.
 */
final class FileExchangeDiscovery
{
    public const OPERATION = 'discovered';

    /** The header is one line; a header longer than this is not the candidate layout. */
    private const HEADER_MAX_BYTES = 8192;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly FileExchangeLedger $ledger,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function discover(Actor $actor, ProviderConnection $connection): FileExchangeDiscoveryReport
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorizeOperator($actor, $tenantId);

        // The caller's connection object is not trusted for tenancy: the row is
        // re-read inside the ambient tenant before any directory is named.
        $owned = ProviderConnection::query()->forTenant($tenantId)->whereKey((int) $connection->id)->first();
        if ($owned === null || (int) $owned->tenant_id !== $tenantId) {
            throw new FileExchangeException('The provider connection is outside the current tenant.');
        }

        $root = $this->root();
        $files = [];

        foreach ($this->entries($root, (int) $owned->id) as $name => $path) {
            $files[] = $this->record($owned, $actor, $root, $name, $path);
        }

        $report = new FileExchangeDiscoveryReport((int) $owned->id, $files);

        // One audit row per run, counts only: the file names are on the ledger
        // rows already and the directory is never written anywhere.
        $this->audit->record($actor, OperatorAuditOperation::FileExchangeDiscovered, (int) $owned->id, null, null, [], $report->counts());

        return $report;
    }

    /** The resolved inbound root; null in config disables discovery outright. */
    private function root(): string
    {
        $configured = config('people-connector.file_exchange.inbound_root');
        if (! is_string($configured) || trim($configured) === '') {
            throw new FileExchangeException('File discovery is disabled: no inbound root is configured (PEOPLE_CONNECTOR_FILE_INBOUND_ROOT).');
        }

        $root = realpath($configured);
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new FileExchangeException('The configured inbound root is not a readable directory.');
        }

        return rtrim($root, '/');
    }

    /**
     * Directory entries by name, sorted. A missing connection directory is an
     * empty run: nothing was dropped yet. A connection directory that itself
     * resolves outside the root is refused before anything inside it is read.
     *
     * @return array<string, string> name => path as listed (not yet resolved)
     */
    private function entries(string $root, int $connectionId): array
    {
        $directory = $root.'/'.$connectionId;
        if (! file_exists($directory)) {
            return [];
        }

        $resolved = realpath($directory);
        if ($resolved === false || ! $this->inside($root, $resolved)) {
            throw new FileExchangeException("The inbound directory for connection [{$connectionId}] resolves outside the inbound root.");
        }
        if (! is_dir($resolved) || ! is_readable($resolved)) {
            throw new FileExchangeException("The inbound directory for connection [{$connectionId}] is not a readable directory.");
        }

        $entries = [];
        foreach (scandir($directory, SCANDIR_SORT_ASCENDING) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $entries[$name] = $directory.'/'.$name;
            }
        }

        return $entries;
    }

    /** @return array{file: string, sha256: ?string, status: string, schema: ?string, reason: ?string} */
    private function record(ProviderConnection $owned, Actor $actor, string $root, string $name, string $path): array
    {
        $resolved = realpath($path);
        if ($resolved === false || ! $this->inside($root, $resolved)) {
            return $this->row($name, null, FileExchangeDiscoveryReport::STATUS_REFUSED, null, 'outside_inbound_root');
        }
        if (is_dir($resolved)) {
            return $this->row($name, null, FileExchangeDiscoveryReport::STATUS_REFUSED, null, 'not_a_regular_file');
        }
        if (! is_file($resolved) || ! is_readable($resolved)) {
            return $this->row($name, null, FileExchangeDiscoveryReport::STATUS_REFUSED, null, 'unreadable');
        }

        $sha256 = hash_file('sha256', $resolved);
        if ($sha256 === false) {
            return $this->row($name, null, FileExchangeDiscoveryReport::STATUS_REFUSED, null, 'unreadable');
        }

        $schema = $this->schema($resolved);
        $record = $this->ledger->record($owned, new ProviderFile($name, $sha256, $resolved), FileExchangeRecord::DIRECTION_IMPORT, self::OPERATION, $actor, $schema);

        return $this->row(
            $name,
            $record->sha256,
            $record->wasRecentlyCreated ? FileExchangeDiscoveryReport::STATUS_RECORDED : FileExchangeDiscoveryReport::STATUS_ALREADY_RECORDED,
            $record->schema_version,
            null,
        );
    }

    /** The candidate HR2000 schema when the first line is exactly its header; null otherwise. Reads one line, never the rows. */
    private function schema(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $line = fgets($handle, self::HEADER_MAX_BYTES);
        } finally {
            fclose($handle);
        }

        if ($line === false) {
            return null;
        }
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            $line = substr($line, 3);
        }

        return str_getcsv(rtrim($line, "\r\n"), ',', '"', '') === Hr2000EmployeeCsvParser::COLUMNS ? Hr2000EmployeeCsvParser::SCHEMA_VERSION : null;
    }

    /** Whether a resolved path is the root or strictly under it (a prefix match on `root/`, never on `root`). */
    private function inside(string $root, string $resolved): bool
    {
        return $resolved === $root || str_starts_with($resolved, $root.'/');
    }

    /** @return array{file: string, sha256: ?string, status: string, schema: ?string, reason: ?string} */
    private function row(string $file, ?string $sha256, string $status, ?string $schema, ?string $reason): array
    {
        return ['file' => $file, 'sha256' => $sha256, 'status' => $status, 'schema' => $schema, 'reason' => $reason];
    }

    /** The same gate connector:doctor uses: the operator capability, held by an actor inside the current tenant. */
    private function authorizeOperator(Actor $actor, int $tenantId): void
    {
        $this->authorization->authorize($actor, ConnectorHealthService::READ_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'file-exchange.discover', 'File discovery requires an operator inside the current tenant.');
        }
    }
}
