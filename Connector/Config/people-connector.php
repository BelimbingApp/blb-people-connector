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
    'retention' => [
        // Progress logs: how far a sync got is operationally useful for a
        // while, and of no interest a year later.
        'people_connector_connector_sync_checkpoint_events' => ['days' => 365, 'column' => 'created_at'],

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
    ],
];
