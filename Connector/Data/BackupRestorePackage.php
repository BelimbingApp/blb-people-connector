<?php

namespace App\Domains\PeopleConnector\Connector\Data;

final readonly class BackupRestorePackage
{
    /**
     * @param  list<string>  $tables
     * @param  array<string, int>  $sourceCounts
     * @param  array<string, mixed>  $redactions
     */
    public function __construct(
        public int $tenantId,
        public array $tables,
        public array $sourceCounts,
        public array $redactions,
        public string $protectedPackagePath,
        public string $offerJson,
    ) {}
}
