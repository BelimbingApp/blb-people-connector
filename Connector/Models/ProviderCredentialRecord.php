<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Data\ProviderCredential;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ProviderCredentialRecord extends TenantOwnedModel
{
    protected $table = 'people_connector_connector_provider_credentials';

    protected static function booted(): void
    {
        self::creating(function (self $credential): void {
            $credential->credential_id ??= 'pcred_'.str()->lower(str()->random(26));
        });
    }

    public function scopeUsable(Builder $query, string $audience, string $scope, DateTimeImmutable $at): void
    {
        $query
            ->whereNull('revoked_at')
            ->where('audience', $audience)
            ->whereJsonContains('scopes', $scope)
            ->where('issued_at', '<=', $at)
            ->where('expires_at', '>', $at);
    }

    public function toCredential(): ProviderCredential
    {
        return new ProviderCredential(
            credentialId: (string) $this->credential_id,
            keyId: (string) $this->key_id,
            providerId: (string) $this->provider_id,
            audience: (string) $this->audience,
            scopes: array_values($this->scopes ?? []),
            issuedAt: new DateTimeImmutable($this->issued_at->toISOString()),
            expiresAt: new DateTimeImmutable($this->expires_at->toISOString()),
            revokedAt: $this->revoked_at === null ? null : new DateTimeImmutable($this->revoked_at->toISOString()),
        );
    }

    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'scopes' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
