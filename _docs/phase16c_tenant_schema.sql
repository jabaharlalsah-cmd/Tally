-- ============================================================================
-- ZeroBook — Phase 16C (Outbound Webhooks) — TENANT schema
-- Subscriptions + the delivery/retry queue. Run against EACH tenant DB.
-- Migration: database/migrations/tenant/2026_07_29_000001_add_webhooks.php
-- ============================================================================
--
-- THE SECRET IS ENCRYPTED, NOT HASHED — the deliberate inversion of 16A.
-- 16A hashes API keys because it only ever VERIFIES what a client sends; a one-way digest is
-- enough and is strictly safer. A webhook secret is the mirror image: ZeroBook must SIGN outgoing
-- deliveries with it, so it has to be recoverable at delivery time. Hashing it would make signing
-- impossible. Hence `secret` is TEXT holding a Laravel `encrypted` cast payload (APP_KEY), and the
-- plaintext is shown to the customer exactly once at create/rotate.
--
--  • No tenant_id (the connection IS the tenant — the 16A convention).
--  • No company_id on the SUBSCRIPTION: it may span companies, so authorization is a JSON id list
--    (empty = all), mirroring api_keys.authorized_company_ids_json.
--  • company_id ON THE DELIVERY records which company's event it was — that is what makes company
--    isolation auditable after the fact.
--  • event_id is STABLE across every retry of an event (the receiver's dedup key). The per-attempt
--    id travels in the X-ZeroBook-Delivery header and is not stored.
--  • payload_json is SNAPSHOTTED at emission: a pending delivery must send what was true when the
--    event happened, even if the voucher is later altered or cancelled.
--  • status='delivering' + claimed_at is the single-flight claim (an atomic conditional UPDATE);
--    a claim older than webhooks.claim_ttl is reclaimable, so a worker that died mid-POST does not
--    strand the row forever.
--
-- Preferred install is `php artisan tenants:migrate --force` (deploy.sh runs it); this is the
-- phpMyAdmin equivalent for one tenant.

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_types_json` json NOT NULL,                                     -- ['voucher.created', …] or ['*']
  `authorized_company_ids_json` json NOT NULL,                          -- [1,2] — empty array = every company
  `secret` text COLLATE utf8mb4_unicode_ci NOT NULL,                    -- ENCRYPTED (recoverable) — see docblock
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_user_id` bigint unsigned DEFAULT NULL,                    -- central tenant_users.id — no cross-DB FK
  `created_by_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_delivery_at` timestamp NULL DEFAULT NULL,
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0',             -- drives auto-disable
  `disabled_at` timestamp NULL DEFAULT NULL,
  `disabled_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webhook_subscriptions_is_active_disabled_at_index` (`is_active`,`disabled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `webhook_subscription_id` bigint unsigned NOT NULL,
  `event_id` varchar(26) COLLATE utf8mb4_unicode_ci NOT NULL,           -- ULID, STABLE across retries
  `event_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` bigint unsigned DEFAULT NULL,                            -- which company's event
  `payload_json` json NOT NULL,                                         -- snapshotted at emission
  `attempt_count` tinyint unsigned NOT NULL DEFAULT '0',
  `status` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',  -- pending|delivering|succeeded|failed|exhausted
  `next_attempt_at` timestamp NULL DEFAULT NULL,                        -- backoff: 5s,30s,5m,30m,3h
  `claimed_at` timestamp NULL DEFAULT NULL,                             -- single-flight claim stamp
  `last_attempted_at` timestamp NULL DEFAULT NULL,
  `last_response_status` smallint unsigned DEFAULT NULL,
  `last_response_body_excerpt` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- THEIR reply, not our data
  `succeeded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webhook_deliveries_status_next_attempt_at_index` (`status`,`next_attempt_at`),   -- the due scan
  KEY `webhook_deliveries_webhook_subscription_id_id_index` (`webhook_subscription_id`,`id`),
  KEY `webhook_deliveries_event_id_index` (`event_id`),
  CONSTRAINT `webhook_deliveries_webhook_subscription_id_foreign` FOREIGN KEY (`webhook_subscription_id`) REFERENCES `webhook_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
