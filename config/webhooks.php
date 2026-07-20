<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Phase 16C — outbound webhook delivery
    |--------------------------------------------------------------------------
    */

    // How long to wait for the customer's endpoint. Deliberately SHORT: the dispatcher runs every
    // minute, so a handful of hung endpoints on a 30s timeout (the HostingerService default) would
    // overrun the tick and stack runs. A webhook receiver that cannot answer in 10s is failing.
    'timeout' => (int) env('ZEROBOOK_WEBHOOK_TIMEOUT', 10),
    'connect_timeout' => (int) env('ZEROBOOK_WEBHOOK_CONNECT_TIMEOUT', 5),

    // Rows claimed per dispatcher tick, per tenant. Bounds the work one minute can start.
    'batch' => (int) env('ZEROBOOK_WEBHOOK_BATCH', 50),

    // Wall-clock budget for ONE tenant's tick. The row count alone does not bound runtime: a full
    // batch of 50 against a hanging endpoint is 50 × timeout = 500s, past the scheduler's 5-minute
    // overlap lock. Kept under that lock deliberately. Unreached rows stay pending for the next
    // tick — under load delivery spreads across ticks rather than piling ticks on top of each other.
    'max_seconds' => (int) env('ZEROBOOK_WEBHOOK_MAX_SECONDS', 240),

    // A row stuck in 'delivering' longer than this is presumed abandoned (the worker died
    // mid-flight) and is reclaimable. Must exceed timeout + a safety margin.
    'claim_ttl' => (int) env('ZEROBOOK_WEBHOOK_CLAIM_TTL', 120),

    // Consecutive EXHAUSTED deliveries before a subscription is auto-disabled. A dead endpoint
    // must not accumulate pending rows forever.
    'auto_disable_after' => (int) env('ZEROBOOK_WEBHOOK_AUTO_DISABLE_AFTER', 20),

    // Delivery-log retention: succeeded/exhausted rows older than this are pruned nightly.
    'retention_days' => (int) env('ZEROBOOK_WEBHOOK_RETENTION_DAYS', 30),

    // Require https:// on subscription URLs. Off in local dev so http://localhost receivers work.
    //
    // NOTE: env('APP_ENV'), never app()->environment() — a config file is loaded BEFORE the
    // application is bootstrapped, so calling app() here fatals with 'Class "env" does not exist'
    // and takes every artisan command down with it.
    'require_https' => (bool) env('ZEROBOOK_WEBHOOK_REQUIRE_HTTPS', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

];
