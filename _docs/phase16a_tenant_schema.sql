-- ============================================================================
-- ZeroBook — Phase 16A (Customer-Integration API) — TENANT schema
-- The authoritative API key rows + the request log. Run against EACH tenant DB.
-- Migration: database/migrations/tenant/2026_07_27_000001_add_api_foundation.php
-- ============================================================================
--
-- Pairs with _docs/phase16a_central_schema.sql (the prefix → tenant router, central DB).
-- Preferred route is `php artisan tenants:migrate --force`, which deploy.sh now runs; this
-- script is the phpMyAdmin equivalent for a single tenant.
--
-- Columns that look like mistakes but are not:
--
--  • NO `tenant_id` column. The connection IS the tenant; a column would be a second source
--    of truth that could disagree with the database the row was read from.
--
--  • `created_by_user_id` / `revoked_by_user_id` carry NO foreign key. Tenant users live in
--    the CENTRAL `tenant_users` table while these rows live in the tenant DB — MySQL cannot
--    enforce a cross-database FK. The email snapshot beside each id is what keeps the audit
--    trail readable after a user is renamed or deleted.
--
--  • NO `company_id` on api_keys. The BelongsToCompany global scope filters by the ACTIVE
--    company, but this row is read BEFORE a company is active — resolving it is what the row
--    is read FOR. Authorized companies are an explicit JSON id list, validated in middleware.
--
--  • `request_body_hash` is a SHA-256 hex digest, never the body. Bodies are PII and
--    unbounded; the digest still answers "did the same payload arrive twice?".
--
--  • The prefix index is tenant-wide, NOT composite with company: the lookup happens before
--    the company is known.

-- 1. The authoritative key row. key_hash is bcrypt (Hash::make) of the FULL raw key;
--    nothing here can reconstruct the secret.
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,                  -- user's label, e.g. "HMS Production"
  `prefix` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,                 -- 'zb_live_a1b2c3d4' — plaintext handle
  `key_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,              -- bcrypt of the full raw key
  `permissions_json` json NOT NULL,                                         -- ["voucher:create", …] or ["*"]
  `authorized_company_ids_json` json NOT NULL,                              -- [1,2] — EMPTY ARRAY = every company
  `rate_limit_per_min` smallint unsigned DEFAULT NULL,                      -- NULL = config('zerobook.api.rate_limit_per_min')
  `created_by_user_id` bigint unsigned DEFAULT NULL,                        -- central tenant_users.id — no FK possible
  `created_by_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,                               -- throttled to one write/minute
  `last_used_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,                                 -- soft delete — the auth authority
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
  `revoked_by_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,                                 -- optional expiry
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_prefix_unique` (`prefix`),
  KEY `api_keys_revoked_at_index` (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- 2. The first tenant-side audit log in the app (platform_admin_actions and
--    tenant_lifecycle_events are both central). Append-only, mirroring their convention:
--    created_at only, no updated_at. Requests that fail authentication are absent by
--    construction — with no valid key there is no tenant to write the row to.
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_request_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_key_id` bigint unsigned NOT NULL,
  `request_id` varchar(26) COLLATE utf8mb4_unicode_ci NOT NULL,             -- ULID, echoed as X-Request-Id
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `query_string` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_body_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,     -- SHA-256 hex — never the body
  `response_status` smallint unsigned NOT NULL,
  `duration_ms` int unsigned NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `api_request_log_api_key_id_created_at_index` (`api_key_id`,`created_at`),
  KEY `api_request_log_request_id_index` (`request_id`),
  CONSTRAINT `api_request_log_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
