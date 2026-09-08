<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use DateTimeImmutable;

/**
 * Outcome of connector:webhook:secret:rotate (#247). Carries the new secret
 * for the one print the command makes; previous secrets are fingerprints.
 *
 * @phpstan-type Entry array{secret: string, expires_at: ?string}
 */
final readonly class WebhookSecretRotation
{
    /**
     * @param  list<Entry>  $entries  the connection's new config entry list, the new secret first as `<new-secret>`; previous secrets as `<fingerprint:xxxxxxxx>` placeholders
     * @param  list<string>  $previousFingerprints
     */
    public function __construct(
        public int $tenantId,
        public int $connectionId,
        public string $newSecret,
        public string $newFingerprint,
        public array $previousFingerprints,
        public int $overlapMinutes,
        public ?DateTimeImmutable $overlapEndsAt,
        public array $entries,
    ) {}
}
