<?php

/**
 * Phase 16 — Hostinger API integration for auto-provisioning tenant infrastructure
 * (a MySQL database + a subdomain vhost per tenant) on shared hosting, where the app's
 * own MySQL user cannot CREATE DATABASE.
 */
return [
    'api_token' => env('HOSTINGER_API_TOKEN'),
    'base_url' => rtrim(env('HOSTINGER_API_URL', 'https://developers.hostinger.com/api'), '/'),

    // The hosting account username (also the mandatory prefix on every DB name/user).
    'account_username' => env('HOSTINGER_ACCOUNT_USERNAME'),

    // The parent website domain tenant subdomains + databases are attached to.
    'website_domain' => env('HOSTINGER_WEBSITE_DOMAIN', 'zerobook.in'),

    // Seconds to wait for an async DB/subdomain create to become usable.
    'provision_timeout' => (int) env('HOSTINGER_PROVISION_TIMEOUT', 60),
];
