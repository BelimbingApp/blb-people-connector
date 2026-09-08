<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WebhookSecretRotation;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use DateTimeImmutable;

/**
 * Rotates a connection's webhook signing secret with an overlap window (#247).
 *
 * Secrets live in config (an environment JSON, never a table: the
 * credentials table holds references only), so this writes nothing but the
 * audit row. It generates the new secret, computes the entry list the
 * operator pastes into PEOPLE_CONNECTOR_WEBHOOK_SECRETS (new secret first,
 * every current secret kept until now + overlap), and records who rotated
 * which connection with the fingerprints of the previous secrets. The new
 * secret is returned for exactly one print; it is never audited or logged.
 */
final class WebhookSecretRotator
{
    public const ROTATE_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly TenantConnectionLocator $connections,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function rotate(Actor $actor, int $connectionId, int $overlapMinutes): WebhookSecretRotation
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorization->authorize($actor, self::ROTATE_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'rotate_webhook_secret', 'Rotating a webhook secret requires an operator inside the current tenant.');
        }
        if ($overlapMinutes < 0 || $overlapMinutes > 60 * 24 * 30) {
            throw new InvalidProviderConfigurationException('The overlap window is between 0 and 43200 minutes.');
        }

        // Tenant-scoped lookup: a connection of another tenant is not found.
        $connection = $this->connections->get($connectionId);
        $now = DateTimeImmutable::createFromInterface(now());
        $overlapEndsAt = $overlapMinutes === 0 ? null : $now->modify("+{$overlapMinutes} minutes");

        $newSecret = bin2hex(random_bytes(32));
        // The block carries a placeholder: the secret is printed once, on its own.
        $entries = [['secret' => '<new-secret>', 'expires_at' => null]];
        $previous = [];
        foreach (WebhookSecrets::entries((int) $connection->id) as $entry) {
            if ($entry['expires_at'] !== null && $entry['expires_at'] <= $now) {
                continue; // already expired: dropped from the new list
            }
            if ($overlapEndsAt === null) {
                $previous[] = WebhookSecrets::fingerprint($entry['secret']);

                continue; // no overlap: previous secrets stop verifying at once
            }
            $expires = $entry['expires_at'] === null || $entry['expires_at'] > $overlapEndsAt ? $overlapEndsAt : $entry['expires_at'];
            $previous[] = $fingerprint = WebhookSecrets::fingerprint($entry['secret']);
            $entries[] = ['secret' => "<fingerprint:{$fingerprint}>", 'expires_at' => $expires->format(DATE_ATOM)];
        }

        $this->audit->record(
            $actor,
            OperatorAuditOperation::WebhookSecretRotated,
            (int) $connection->id,
            null,
            null,
            ['previous_fingerprints' => $previous, 'entries' => count($previous)],
            ['overlap_minutes' => $overlapMinutes, 'overlap_ends_at' => $overlapEndsAt?->format(DATE_ATOM), 'new_fingerprint' => WebhookSecrets::fingerprint($newSecret), 'entries' => count($entries), 'persisted' => 'config'],
        );

        return new WebhookSecretRotation($tenantId, (int) $connection->id, $newSecret, WebhookSecrets::fingerprint($newSecret), $previous, $overlapMinutes, $overlapEndsAt, $entries);
    }
}
