-- ============================================================================
-- ZeroBook — Phase 16A (Customer-Integration API) — CENTRAL schema
-- The prefix → tenant router. Run ONCE against the central DB (zerobook_central).
-- Migration: database/migrations/2026_07_27_000001_create_api_key_directory.php
-- ============================================================================
--
-- WHY THIS TABLE EXISTS (it is not in the 16A brief, and 16A does not work without it):
--
-- The authoritative `api_keys` row lives in each TENANT database — that is what makes
-- "tenant A cannot see tenant B's keys" a property of the schema rather than of a WHERE
-- clause. But an API request arrives carrying ONLY `Authorization: Bearer zb_live_…`:
-- no subdomain, no session, no cookie. So the first thing the middleware must do — decide
-- which tenant database to open — cannot be answered by a table that can only be read
-- after that decision. Without a central index the alternatives are to scan every tenant
-- DB on every request, or to encode the tenant into the key (which the fixed
-- zb_<env>_<32 random> format forbids).
--
-- This table therefore stores the one fact needed before a tenant connection exists.
-- It is a ROUTER, not an authority:
--   • It holds NO secret. `prefix` is plaintext by design (it is the lookup handle);
--     the hashed key never leaves the tenant DB. Reading this whole table grants nothing.
--   • It never decides auth. Both the hash verify and the revoked/expiry check run against
--     the tenant-DB row, so a stale row here cannot authenticate anything.
--
-- `revoked_at` here is a housekeeping MIRROR only and is deliberately not consulted on the
-- auth path, so the tenant row stays the single source of truth for revocation.
--
-- The FK cascades on tenant delete so a torn-down/purged tenant leaves no routing residue.

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_key_directory` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prefix` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,        -- 'zb_live_a1b2c3d4' — plaintext handle, not a secret
  `tenant_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,    -- tenants.id IS the subdomain slug
  `revoked_at` timestamp NULL DEFAULT NULL,                        -- mirror only; NEVER read on the auth path
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key_directory_prefix_unique` (`prefix`),
  KEY `api_key_directory_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `api_key_directory_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
