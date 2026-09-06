<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use DateTimeImmutable;

/**
 * Authenticates a provider callback without interpreting its payload.
 *
 * The connection id, timestamp and raw request bytes are the signed message.
 * Signing the connection id prevents a valid secret/message pair from being
 * replayed at another connection endpoint, while leaving projection parsing
 * entirely inside the normal provider pass.
 */
final class WorkforceWebhookVerifier
{
    public const TIMESTAMP_HEADER = 'X-People-Connector-Timestamp';

    public const SIGNATURE_HEADER = 'X-People-Connector-Signature';

    public function verify(
        int $connectionId,
        string $body,
        ?string $timestamp,
        ?string $signature,
        ?DateTimeImmutable $now = null,
    ): void {
        $timestamp = trim((string) $timestamp);
        $signature = trim((string) $signature);

        if (preg_match('/^[0-9]+$/', $timestamp) !== 1) {
            throw new WebhookRefusal('malformed_timestamp', 'The webhook timestamp is missing or malformed.');
        }

        $tolerance = config('people-connector.webhook.timestamp_tolerance_seconds', 300);
        if (! is_int($tolerance) || $tolerance < 1) {
            throw new WebhookRefusal('unconfigured', 'Webhook verification is not configured.');
        }

        $now ??= new DateTimeImmutable;
        if (abs($now->getTimestamp() - (int) $timestamp) > $tolerance) {
            throw new WebhookRefusal('stale_timestamp', 'The webhook timestamp is outside the allowed window.');
        }

        $secret = config("people-connector.webhook.secrets.{$connectionId}");
        if (! is_string($secret) || $secret === '') {
            throw new WebhookRefusal('unconfigured', 'Webhook verification is not configured for this connection.');
        }

        $signature = preg_replace('/^sha256=/', '', $signature) ?? '';
        if (preg_match('/^[a-f0-9]{64}$/i', $signature) !== 1) {
            throw new WebhookRefusal('invalid_signature', 'The webhook signature is invalid.');
        }

        $message = $connectionId."\n".$timestamp."\n".$body;
        $expected = hash_hmac('sha256', $message, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            throw new WebhookRefusal('invalid_signature', 'The webhook signature is invalid.');
        }
    }
}
