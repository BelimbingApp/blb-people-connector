<?php

namespace App\Domains\PeopleConnector\Connector\Testing;

use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ReconcilesWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;

final class ProviderConformance
{
    /** @return list<string> */
    public static function violations(
        ProviderAdapter $provider,
        int $supportedContractMajor = 1,
        int $maximumBootstrapPages = 100,
    ): array {
        $violations = [];
        $descriptor = $provider->descriptor();

        if ($descriptor->contractMajor() !== $supportedContractMajor) {
            $violations[] = 'contract_major_mismatch';
        }

        try {
            $provider->health();
        } catch (\Throwable) {
            $violations[] = 'provider_health_failed';
        }

        $ports = [];
        if (! $provider instanceof ResolvesProviderPorts) {
            $violations[] = 'provider_port_resolver_missing';

            return $violations;
        }

        $conformanceAuthorization = ProviderPortAuthorization::forConformance($descriptor->id);

        foreach ($provider->capabilities()->all() as $declaration) {
            foreach ($declaration->portContracts() as $contract) {
                if (isset($ports[$contract])) {
                    continue;
                }

                try {
                    $port = $provider->resolvePort($contract, $conformanceAuthorization);
                } catch (\Throwable) {
                    $violations[] = "port_resolution_failed:{$contract}";

                    continue;
                }

                if (! $port instanceof $contract) {
                    $violations[] = "declared_port_unavailable:{$contract}";

                    continue;
                }

                $ports[$contract] = $port;
            }
        }

        $bootstrap = self::firstPort($ports, BootstrapsWorkforce::class);
        $changes = self::firstPort($ports, ReadsWorkforceChanges::class);
        $reconciliation = self::firstPort($ports, ReconcilesWorkforce::class);
        $resumeCursor = null;

        if ($bootstrap instanceof BootstrapsWorkforce) {
            $pageCursor = null;

            for ($pageNumber = 0; $pageNumber < $maximumBootstrapPages; $pageNumber++) {
                try {
                    $page = $bootstrap->bootstrap(new WorkforcePageRequest($pageCursor, limit: 1000));
                } catch (\Throwable) {
                    $violations[] = 'workforce_bootstrap_failed';

                    break;
                }

                if ($page->complete) {
                    $resumeCursor = $page->resumeCursor;

                    break;
                }

                $pageCursor = $page->nextPageCursor;
            }

            if ($resumeCursor === null && ! in_array('workforce_bootstrap_failed', $violations, true)) {
                $violations[] = 'workforce_bootstrap_page_limit_exceeded';
            }
        }

        if ($changes instanceof ReadsWorkforceChanges) {
            if ($resumeCursor === null) {
                $violations[] = 'workforce_changes_require_bootstrap_checkpoint';
            } else {
                try {
                    $changes->changes(new WorkforceChangeRequest($resumeCursor, limit: 1));
                } catch (\Throwable) {
                    $violations[] = 'workforce_changes_failed';
                }
            }
        }

        if ($reconciliation instanceof ReconcilesWorkforce) {
            try {
                $reconciliation->reconcile();
            } catch (\Throwable) {
                $violations[] = 'workforce_reconciliation_failed';
            }
        }

        return $violations;
    }

    /**
     * @param  array<class-string, object>  $ports
     * @param  class-string  $contract
     */
    private static function firstPort(array $ports, string $contract): ?object
    {
        foreach ($ports as $port) {
            if ($port instanceof $contract) {
                return $port;
            }
        }

        return null;
    }
}
