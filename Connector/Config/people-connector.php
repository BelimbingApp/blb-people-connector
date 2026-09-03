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
];
