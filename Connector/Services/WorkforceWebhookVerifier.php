<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Exceptions\WebhookRefusal;
use DateTimeImmutable;

/**
 * Authenticates a provider callback without interpreting its payload.
 *
 * The connection id, timestamp, delivery id and raw request bytes are signed.
 * Binding every routing and replay-control input to the signature keeps a valid
 * callback from being moved to another connection or replayed under a new id.
 * Whether a delivery id was already accepted is the receipt ledger's question
 * (WebhookReceiptLedger, #227), not this verifier's.
 */
final class WorkforceWebhookVerifier
{
    public const TIMESTAMP_HEADER = 'X-People-Connector-Timestamp';

    public const SIGNATURE_HEADER = 'X-People-Connector-Signature';

    public const DELIVERY_HEADER = 'X-People-Connector-Delivery';

    public function verify(
        int $connectionId,
        string $body,
        ?string $timestamp,
        ?string $deliveryId,
        ?string $signature,
        ?DateTimeImmutable $now = null,
    ): void {
        $timestamp = trim((string) $timestamp);
        $deliveryId = trim((string) $deliveryId);
        $signature = trim((string) $signature);

        $maxPayloadBytes = config('people-connector.webhook.max_payload_bytes', 1048576);
        if (! is_int($maxPayloadBytes) || $maxPayloadBytes < 1) {
            throw new WebhookRefusal('unconfigured', 'Webhook verification is not configured.');
        }

        if (strlen($body) > $maxPayloadBytes) {
            throw new WebhookRefusal('payload_too_large', 'The webhook payload exceeds the configured limit.');
        }

        if (preg_match('/^[0-9]+$/', $timestamp) !== 1) {
            throw new WebhookRefusal('malformed_timestamp', 'The webhook timestamp is missing or malformed.');
        }

        $tolerance = config('people-connector.webhook.timestamp_tolerance_seconds', 300);
        if (! is_int($tolerance) || $tolerance < 1) {
            throw new WebhookRefusal('unconfigured', 'Webhook verification is not configured.');
        }

        if (preg_match('/^[\x21-\x7E]{1,128}$/D', $deliveryId) !== 1) {
            throw new WebhookRefusal('malformed_delivery_id', 'The webhook delivery id is missing or malformed.');
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

        $message = $connectionId."\n".$timestamp."\n".$deliveryId."\n".$body;
        $expected = hash_hmac('sha256', $message, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            throw new WebhookRefusal('invalid_signature', 'The webhook signature is invalid.');
        }
    }
}
