<?php

namespace App\Domains\PeopleConnector\Connector\Services;

final class ScratchRestoreEnvironment
{
    /** @return array<string, string> */
    public function forDatabase(string $databaseUrl, string $handoffPath): array
    {
        return [
            'APP_CONFIG_CACHE' => $handoffPath.'.no-config-cache',
            'APP_URL' => 'https://rehearsal-'.substr(hash('sha256', $databaseUrl), 0, 20).'.invalid',
            'DB_URL' => $databaseUrl,
            'PEOPLE_CONNECTOR_REHEARSAL_HANDOFF' => $handoffPath,
        ];
    }
}
