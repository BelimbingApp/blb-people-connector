<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\WebhookSecretRotator;

/**
 * Rotate a connection's webhook signing secret with an overlap window (#247).
 * Prints the new secret exactly once and the config entry list to paste;
 * writes nothing but the audit row.
 */
final class WebhookSecretRotateCommand extends TenantScopedCommand
{
    protected $signature = 'connector:webhook:secret:rotate
                            {connection : Provider connection id}
                            {--overlap-minutes=60 : How long the previous secret keeps verifying deliveries}
                            {--as= : Id of the operator this rotation runs as}';

    protected $description = 'Generate a new webhook signing secret for a connection, keeping the previous one for an overlap window';

    public function handle(TenantContext $tenants, WebhookSecretRotator $rotator): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A secret rotation runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        $overlap = $this->option('overlap-minutes');
        if (! is_numeric($overlap) || (int) $overlap != $overlap) {
            $this->error('--overlap-minutes takes a whole number of minutes.');

            return self::FAILURE;
        }

        try {
            $rotation = $rotator->rotate(Actor::forUser($operator), (int) $this->argument('connection'), (int) $overlap);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|InvalidProviderConfigurationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Rotated the webhook secret for connection {$rotation->connectionId} (tenant {$rotation->tenantId}); audit row written, fingerprint {$rotation->newFingerprint}.");
        $previous = $rotation->previousFingerprints === [] ? 'none' : implode(', ', $rotation->previousFingerprints);
        $this->line($rotation->overlapEndsAt === null
            ? 'No overlap: previous secrets stop verifying as soon as the config changes.'
            : "Previous secrets ({$previous}) keep verifying until {$rotation->overlapEndsAt->format(DATE_ATOM)}.");
        $this->newLine();
        $this->line('New secret (shown once; it is not stored anywhere):');
        $this->line($rotation->newSecret);
        $this->newLine();
        $this->line("Replace this connection's entry in PEOPLE_CONNECTOR_WEBHOOK_SECRETS with the list below, substituting <new-secret> with the secret above and each <fingerprint:...> placeholder with the current secret it stands for:");
        $this->line(json_encode([(string) $rotation->connectionId => $rotation->entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
