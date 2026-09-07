<?php

return [
    'active_provider' => null,
    'supported_contract_major' => 1,

    /*
     * Workforce synchronisation ([1006]). One checkpoint stream serves the
     * bootstrap and incremental passes, because the bootstrap's resume cursor
     * is what the first incremental read presents. max_age_minutes is measured
     * from the provider's as-of watermark, not from when the connector last
     * ran; beyond it WorkforceFreshnessPolicy reports the projections stale
     * and assertFresh() fails closed.
     */
    'sync' => [
        'stream' => 'workforce',
        'page_limit' => 250,
        'max_age_minutes' => 1440,

        /*
         * How many consecutive passes may be refused at the same cursor before
         * the page is parked for an operator. Retrying a page that the provider
         * and the connector disagree about is not going to change either of
         * their minds; past this point the retry is noise and the disagreement
         * is the thing worth surfacing.
         */
        'dead_letter_attempts' => 3,
    ],

    /*
     * Command reconciliation ([1009-c]). A provider absence after an unknown
     * transport outcome is retryable, but it is not permission to retry
     * forever. The final decision parks the idempotency key in the operator
     * reconciliation queue instead of sending another blind attempt.
     */
    'command_reconciliation' => [
        'max_attempts' => 3,
        'backoff_seconds' => 60,
    ],

    /*
     * Provider webhook trigger ([1009-g]). Disabled by default: a deployment
     * must opt in and provide a secret per connection. The JSON environment
     * value keeps raw secrets out of source control while allowing immutable
     * config caching.
     */
    'webhook' => [
        'enabled' => env('PEOPLE_CONNECTOR_WEBHOOK_ENABLED', false),
        'timestamp_tolerance_seconds' => (int) env('PEOPLE_CONNECTOR_WEBHOOK_TOLERANCE_SECONDS', 300),
        'max_payload_bytes' => (int) env('PEOPLE_CONNECTOR_WEBHOOK_MAX_PAYLOAD_BYTES', 1048576),
        'secrets' => json_decode((string) env('PEOPLE_CONNECTOR_WEBHOOK_SECRETS', '{}'), true) ?: [],
        'delivery_policy' => [
            'max_attempts' => 3,
            'backoff_seconds' => [60, 300],
        ],
    ],

    /*
     * Retention per connector-owned table ([1012]). `days` is the period rows
     * are kept for, measured from `column`; null is indefinite and needs no
     * column, because "we keep this forever" reads no clock.
     *
     * Indefinite is a decision, not a gap. Every owned table is listed so the
     * report can show the whole policy, and so a table nobody has thought about
     * is visible as such rather than silently absent.
     *
     * Nothing here deletes. RetentionPolicy reports what is past retention; the
     * purge that acts on it is a separate, separately approved step.
     */
    /*
     * Delegated authority for employee commands ([1010]). The secret signs and
     * verifies short-lived, audience-bound tokens; there is no default, because
     * a default signing key is a key everyone has.
     */
    'delegation' => [
        'secret' => env('PEOPLE_CONNECTOR_DELEGATION_SECRET'),
        'max_lifetime_seconds' => 300,
    ],

    /*
     * Machine-readable capability evidence register (#209): which
     * PeopleCapability values each provider has deployment evidence for.
     * connector:health:check reports adapter declarations that drift from it.
     */
    'capability_register' => env('PEOPLE_CONNECTOR_CAPABILITY_REGISTER', __DIR__.'/../../docs/providers/capability-register.json'),

    'retention' => [
        // Progress logs: how far a sync got is operationally useful for a
        // while, and of no interest a year later.
        'people_connector_connector_sync_checkpoint_events' => ['days' => 365, 'column' => 'created_at'],

        // Webhook deliveries (#223): the trigger ledger an operator replays a
        // failed delivery from. A year-old delivery is not worth re-sending;
        // the replay itself is on the operator audit, which is kept.
        'people_connector_connector_webhook_deliveries' => ['days' => 365, 'column' => 'received_at'],

        // Inbound idempotency ledger (#227): a delivery id is a duplicate for
        // seven days; after that the provider would not resend it anyway.
        'people_connector_connector_webhook_receipts' => ['days' => 7, 'column' => 'first_seen_at'],

        // Scheduled operator health is deliberately short-lived. It is trend
        // context, not an audit log; durable operator actions live elsewhere.
        'people_connector_connector_doctor_snapshots' => ['days' => 30, 'column' => 'measured_at'],

        // Reconciliation issues age out from when they were resolved, so an
        // issue still open is never past retention however old it is.
        'people_connector_connector_reconciliation_issues' => ['days' => 365, 'column' => 'resolved_at'],

        // The history spine and the state it describes. Privacy erasure redacts
        // these in place (see PrivacyDeletionService) rather than deleting
        // them, which is why they are kept rather than aged out.
        'people_connector_connector_workforce_snapshots' => ['days' => null],
        'people_connector_connector_workforce_entities' => ['days' => null],
        'people_connector_connector_external_identities' => ['days' => null],
        'people_connector_connector_workforce_companies' => ['days' => null],
        'people_connector_connector_workforce_organization_units' => ['days' => null],
        'people_connector_connector_workforce_positions' => ['days' => null],
        'people_connector_connector_workforce_employees' => ['days' => null],
        'people_connector_connector_sync_checkpoints' => ['days' => null],
        'people_connector_connector_provider_connections' => ['days' => null],

        // Break-glass evidence: who reached past the boundary, when, and under
        // whose grant. Kept indefinitely, and not only as a preference — the
        // models refuse update and delete outright, so a finite window here
        // would be a policy no purge could ever carry out.
        'people_connector_connector_privileged_support_actions' => ['days' => null],
        'people_connector_connector_privileged_support_grants' => ['days' => null],

        // Credential references, not credentials. Current state belonging to a
        // connection, so it lives and dies with the connection rather than
        // ageing out on a clock of its own.
        'people_connector_connector_provider_credentials' => ['days' => null],

        // What a destructive run was authorized to remove is durable evidence,
        // so purge audit rows are themselves never purged.
        'people_connector_connector_retention_purge_audits' => ['days' => null],
        'people_connector_connector_operator_audits' => ['days' => null],
    ],
];
