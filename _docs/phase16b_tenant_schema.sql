-- ============================================================================
-- ZeroBook — Phase 16B (Core REST API) — TENANT schema
-- API write idempotency. Run against EACH tenant DB.
-- Migration: database/migrations/tenant/2026_07_28_000001_add_api_idempotency.php
-- ============================================================================
--
-- The single-flight arbiter for API writes. A write INSERTs a `pending` row here BEFORE it does
-- any work; the unique (api_key_id, idempotency_key) index rejects a concurrent duplicate, so a
-- retry replays the stored response instead of posting a second voucher. See the migration
-- docblock for the full insert-first rationale.
--
--  • No tenant_id (the connection IS the tenant — the 16A convention).
--  • REAL same-DB FK to api_keys (both tenant-DB), cascading on key delete.
--  • request_body_hash folds in the ACTIVE COMPANY, not just the body: the company is chosen by
--    the X-Company-Id header, so the same key + same body to a different company must be a 409
--    reused, never a wrong-company replay.
--  • response_status NULL = still in flight (a concurrent hit gets 409 + Retry-After).
--  • response_body stores ONLY the success response to replay — never the raw request body.
--  • expires_at = 48h; a daily prune sweeps past it.
--
-- Preferred install is `php artisan tenants:migrate --force` (deploy.sh runs it since 16A); this
-- is the phpMyAdmin equivalent for one tenant.

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_idempotency_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_key_id` bigint unsigned NOT NULL,
  `idempotency_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,   -- opaque, client-chosen
  `request_body_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,     -- SHA-256(company : body)
  `response_status` smallint unsigned DEFAULT NULL,                     -- NULL = in flight
  `response_body` json DEFAULT NULL,                                    -- the success body to replay
  `resource_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- 'voucher' | 'ledger' | 'stock_item'
  `resource_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_idem_key_unique` (`api_key_id`,`idempotency_key`),    -- the single-flight arbiter
  KEY `api_idempotency_keys_expires_at_index` (`expires_at`),           -- prune scan
  CONSTRAINT `api_idempotency_keys_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
