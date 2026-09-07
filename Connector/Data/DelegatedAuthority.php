<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\DelegatedAuthorityRefusal;
use App\Domains\PeopleConnector\Connector\Exceptions\DelegatedAuthorityException;
use App\Domains\PeopleConnector\Connector\Services\DelegationPolicy;

/**
 * Permission to do one thing, for one subject, at one service, for a short
 * while.
 *
 * Transport-neutral on purpose. The same value is what an in-process caller
 * hands the port and what a signed token decodes to, so the backend can ask the
 * same questions either way — which is the only reason the HTTP path is allowed
 * to exist at all.
 */
final readonly class DelegatedAuthority
{
    /**
     * The token's own identity (`jti`). A random one is minted unless the
     * caller supplies one; it is what the spend ledger keys on, so a token
     * presented twice is recognisable as the same token and not merely as
     * two tokens that happen to say the same thing.
     */
    public string $id;

    public function __construct(
        public string $subject,
        public int $tenantId,
        public ?int $companyId,
        public string $operation,
        public string $audience,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
        ?string $id = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));

        foreach (['subject' => $subject, 'operation' => $operation, 'audience' => $audience] as $field => $value) {
            if (trim($value) === '' || strlen($value) > 191) {
                throw new DelegatedAuthorityException("A delegated authority {$field} must be a non-empty identifier under 192 bytes.");
            }
        }

        if (trim($this->id) === '' || strlen($this->id) > 64) {
            throw new DelegatedAuthorityException('A delegated authority id must be a non-empty identifier under 65 bytes.');
        }

        if ($tenantId < 1) {
            throw new DelegatedAuthorityException('A delegated authority names a tenant.');
        }

        if ($expiresAt <= $issuedAt) {
            throw new DelegatedAuthorityException('A delegated authority expires after it is issued.');
        }
    }

    /**
     * The backend's own question, asked regardless of how this arrived.
     *
     * Expiry is checked here as well as at verification, and that is not
     * belt-and-braces. Verification happens when a token is presented;
     * this happens when it is spent, and a caller that verified minutes ago
     * must not be able to spend it now.
     */
    public function assertUsableBy(int $tenantId, string $operation, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable;
        $skew = DelegationPolicy::clockSkewSeconds();

        if ($this->tenantId !== $tenantId) {
            throw new DelegatedAuthorityException(
                "This authority was issued for tenant {$this->tenantId}, not {$tenantId}.",
                DelegatedAuthorityRefusal::WrongTenant,
            );
        }

        if ($this->operation !== $operation) {
            throw new DelegatedAuthorityException(
                "This authority permits [{$this->operation}], not [{$operation}].",
                DelegatedAuthorityRefusal::WrongOperation,
            );
        }

        // The issuer's clock and ours need not agree to the second; a bounded
        // tolerance keeps a token minted at the top of the minute from dying
        // early on a host that runs a little ahead. Unbounded it would be a
        // longer lifetime by another name, which is why the bound is config.
        if ($now > $this->expiresAt->modify("+{$skew} seconds")) {
            throw new DelegatedAuthorityException('This authority has expired.', DelegatedAuthorityRefusal::Expired);
        }
    }

    /** @return array<string, int|string|null> */
    public function claims(): array
    {
        return [
            'sub' => $this->subject,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'op' => $this->operation,
            'aud' => $this->audience,
            'iat' => $this->issuedAt->format(DATE_ATOM),
            'exp' => $this->expiresAt->format(DATE_ATOM),
            'jti' => $this->id,
        ];
    }

    /** @param array<string, mixed> $claims */
    public static function fromClaims(array $claims): self
    {
        foreach (['sub', 'tenant_id', 'op', 'aud', 'iat', 'exp', 'jti'] as $required) {
            if (! array_key_exists($required, $claims)) {
                throw new DelegatedAuthorityException("A delegated authority is missing its [{$required}] claim.");
            }
        }

        return new self(
            subject: (string) $claims['sub'],
            tenantId: (int) $claims['tenant_id'],
            companyId: isset($claims['company_id']) ? (int) $claims['company_id'] : null,
            operation: (string) $claims['op'],
            audience: (string) $claims['aud'],
            issuedAt: new \DateTimeImmutable((string) $claims['iat']),
            expiresAt: new \DateTimeImmutable((string) $claims['exp']),
            id: (string) $claims['jti'],
        );
    }
}
