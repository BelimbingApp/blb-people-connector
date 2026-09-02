<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use DateTimeImmutable;

final readonly class ProviderCredential
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $credentialId,
        public string $keyId,
        public string $providerId,
        public string $audience,
        public array $scopes,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
        if ($credentialId === '' || $keyId === '' || $providerId === '') {
            throw new \InvalidArgumentException('Provider credentials require stable identifiers.');
        }

        if ($expiresAt <= $issuedAt || $expiresAt->getTimestamp() - $issuedAt->getTimestamp() > 300) {
            throw new \InvalidArgumentException('Provider credentials must expire within five minutes of issuance.');
        }

        if ($this->revokedAt !== null && $this->revokedAt < $this->issuedAt) {
            throw new \InvalidArgumentException('Provider credentials cannot be revoked before issuance.');
        }
    }

    public function allows(string $audience, string $scope, DateTimeImmutable $at): bool
    {
        return $this->revokedAt === null
            && $this->audience === $audience
            && in_array($scope, $this->scopes, true)
            && $at >= $this->issuedAt
            && $at < $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function publicClaims(): array
    {
        return [
            'credential_id' => $this->credentialId,
            'key_id' => $this->keyId,
            'provider_id' => $this->providerId,
            'audience' => $this->audience,
            'scopes' => $this->scopes,
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
