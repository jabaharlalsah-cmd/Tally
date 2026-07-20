<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ZeroBook — SaaS operations config (Phase 14A)
|--------------------------------------------------------------------------
| Public self-signup + platform-admin surface. Everything here is reconfigurable
| without a code change: the trial length, the reserved subdomains a customer may
| not claim, and the country → tax-regime defaults the signup form offers.
*/

return [

    /**
     * How many days a fresh trial lasts. A new signup gets trial_ends_at = now + this,
     * on the 'trial' plan (all features unlocked). zerobook:trial-check flips a tenant
     * whose trial has lapsed to 'expired_trial' (read-only). Extendable by a platform
     * admin ("Extend trial").
     */
    'trial_days' => (int) env('ZEROBOOK_TRIAL_DAYS', 30),

    /**
     * The plan a self-signup lands on. Must exist in the central `plans` table.
     */
    'signup_plan_tier' => env('ZEROBOOK_SIGNUP_PLAN', 'trial'),

    /**
     * Phase 14B — grace period (days) after a paid subscription's plan_ends_at passes,
     * during which the tenant stays 'active' with a renewal banner and writes still work.
     * After grace, zerobook:trial-check flips the tenant to 'expired_subscription' and
     * writes are blocked (reads always continue).
     */
    'grace_days' => (int) env('ZEROBOOK_GRACE_DAYS', 7),

    /**
     * Phase 14B — how many days before plan_ends_at the "renew soon" reminder email fires.
     */
    'reminder_days' => (int) env('ZEROBOOK_REMINDER_DAYS', 7),

    /**
     * Phase 14B — how many customer-initiated payment CLAIMS a single tenant may submit
     * per day (anti-spam; admin-recorded payments are unlimited).
     */
    'max_claims_per_day' => (int) env('ZEROBOOK_MAX_CLAIMS_PER_DAY', 5),

    /**
     * Phase 14C — backup retention. A backup is kept for the LONGEST window it qualifies
     * for: monthly (taken on the 1st), weekly (taken on a Sunday), else daily. The nightly
     * prune deletes backups past their computed expiry. Enterprise plans keep longer.
     */
    'backup_retention' => [
        'default' => ['daily_days' => 7, 'weekly_weeks' => 4, 'monthly_months' => 12],
        'enterprise' => ['daily_days' => 14, 'weekly_weeks' => 8, 'monthly_months' => 24],
    ],

    /**
     * Phase 14C — the offboarding state machine timeline (days at each step). Aligned with
     * common data-retention windows; tune per your legal requirements.
     *   suspended(offboarding) → +archive_days → archived
     *   archived               → +purge_schedule_days → purge_scheduled
     *   purge_scheduled        → +purge_days → purged (point of no return)
     */
    'offboarding' => [
        'archive_days' => (int) env('ZEROBOOK_OFFBOARD_ARCHIVE_DAYS', 30),
        'purge_schedule_days' => (int) env('ZEROBOOK_OFFBOARD_PURGE_SCHEDULE_DAYS', 90),
        'purge_days' => (int) env('ZEROBOOK_OFFBOARD_PURGE_DAYS', 30),
    ],

    /**
     * Phase 14C — data export: how long a download link stays valid, and the rate limits
     * (exports are expensive; offboarding must not be double-clicked into oblivion).
     */
    /**
     * Phase 14C — directory holding `mysqldump` / `mysql` for backups & exports. On dev
     * (Laragon) this resolves to the bundled binaries; if the path doesn't exist (e.g.
     * production), BackupService falls back to the executables on PATH.
     */
    'mysql_bin_dir' => env('ZEROBOOK_MYSQL_BIN', 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin'),

    'export_link_days' => (int) env('ZEROBOOK_EXPORT_LINK_DAYS', 7),
    'export_min_hours' => (int) env('ZEROBOOK_EXPORT_MIN_HOURS', 24),   // 1 export / 24h
    'offboard_min_days' => (int) env('ZEROBOOK_OFFBOARD_MIN_DAYS', 7),  // 1 initiation / 7d

    /**
     * Phase 14B — the seller identity printed on the subscription-payment invoice
     * (ZeroBook billing the customer for their subscription). Editable without code.
     */
    'invoice' => [
        'seller_name' => env('ZEROBOOK_INVOICE_SELLER', 'ZeroBook'),
        'seller_address' => env('ZEROBOOK_INVOICE_ADDRESS', 'ZeroBook Technologies'),
        'seller_email' => env('ZEROBOOK_INVOICE_EMAIL', 'billing@zerobook.in'),
        'number_prefix' => env('ZEROBOOK_INVOICE_PREFIX', 'ZB'),
    ],

    /**
     * Subdomains a customer may NEVER claim through the public signup: platform
     * infrastructure, the admin surface, and reserved product words. The check is
     * case-insensitive. Existing tenant slugs are rejected separately (collision),
     * so real tenant names (demo/fxui/…) are deliberately NOT listed here.
     *
     * `admin` is the platform-admin subdomain and must stay reserved.
     */
    'reserved_subdomains' => [
        // The three the prompt names explicitly, first.
        'admin', 'www', 'api', 'mail', 'docs', 'blog', 'app', 'status', 'support',
        // Infrastructure / DNS.
        'root', 'ns', 'ns1', 'ns2', 'mx', 'smtp', 'imap', 'pop', 'pop3', 'webmail',
        'cpanel', 'whm', 'ftp', 'sftp', 'ssh', 'vpn', 'cdn', 'assets', 'static',
        'media', 'img', 'images', 'files', 'download', 'downloads',
        // Product / brand / auth surfaces.
        'zerobook', 'www2', 'web', 'portal', 'dashboard', 'my', 'account', 'accounts',
        'login', 'signin', 'signup', 'register', 'auth', 'sso', 'oauth', 'secure',
        'billing', 'pay', 'payments', 'checkout', 'invoice', 'invoices',
        'help', 'helpdesk', 'kb', 'wiki', 'faq', 'contact', 'about', 'pricing',
        'terms', 'privacy', 'legal', 'careers', 'jobs', 'press', 'news',
        // Environments.
        'dev', 'develop', 'development', 'stage', 'staging', 'test', 'testing',
        'qa', 'uat', 'sandbox', 'preview', 'beta', 'alpha', 'internal', 'ops',
        'monitor', 'monitoring', 'grafana', 'kibana', 'metrics', 'health',
    ],

    /**
     * The two markets this phase supports at signup. The chosen country sets the
     * default tax regime and base currency on the tenant's default company during
     * provisioning. Extensible later — two options is enough for pilots.
     *
     *   regime  : 'gst' (India) | 'vat' (Nepal)  → the CompanyFeature flag switched on
     *   currency: the ISO code the default company's base currency is set to
     */
    'countries' => [
        'india' => [
            'label' => 'India',
            'regime' => 'gst',
            'currency' => 'INR',
            'currency_symbol' => '₹',
            'currency_name' => 'Indian Rupee',
            'blurb' => 'GST regime, ₹ INR base — CGST/SGST/IGST duty ledgers.',
        ],
        'nepal' => [
            'label' => 'Nepal',
            'regime' => 'vat',
            'currency' => 'NPR',
            'currency_symbol' => 'रू',
            'currency_name' => 'Nepalese Rupee',
            'blurb' => 'VAT regime, रू NPR base — Output/Input VAT duty ledgers.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 16A — customer-integration API
    |--------------------------------------------------------------------------
    */

    'api' => [
        // Requests per minute for a key that does not override it (api_keys.rate_limit_per_min).
        // A bulk integration (e.g. an HMS posting a night's billing) asks for a higher per-key
        // ceiling rather than this default being raised for everyone.
        'rate_limit_per_min' => (int) env('ZEROBOOK_API_RATE_LIMIT', 60),
    ],
];
