<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

use RuntimeException;

final class BackupRestoreRehearsalException extends RuntimeException
{
    public static function multiTenantScope(): self
    {
        return new self('The DataShare scope contains connector rows from another tenant. Rehearsal requires a single-tenant connector scope.');
    }

    public static function scratchNotConfigured(): self
    {
        return new self('Configure PEOPLE_CONNECTOR_REHEARSAL_DATABASE_URL with a separately provisioned scratch database.');
    }

    public static function scratchNotEmpty(): self
    {
        return new self('The scratch database already contains connector rows; refusing to overwrite it.');
    }

    public static function scratchFailed(string $message): self
    {
        return new self('Scratch restoration failed: '.$message);
    }
}
