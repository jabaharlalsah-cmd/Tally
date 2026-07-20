-- ZeroBook Desktop (Phase 7C) — local SQLite schema (in the app-data dir).
-- The offline mirror + outbox. The server (MySQL, per tenant) stays authoritative;
-- these tables are provisional until a row is confirmed by /api/sync/push.

-- The write queue: every voucher entered on the desktop lands here immediately.
CREATE TABLE IF NOT EXISTS sync_outbox (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    client_uuid   TEXT NOT NULL UNIQUE,   -- idempotency key sent to the server
    entity        TEXT NOT NULL DEFAULT 'voucher',
    payload       TEXT NOT NULL,          -- the exact VoucherScreen::post() JSON payload
    voucher_date  TEXT NOT NULL,          -- drives the chronological drain order
    seq           INTEGER NOT NULL DEFAULT 0,
    status        TEXT NOT NULL DEFAULT 'pending', -- pending | posted | error
    server_id     INTEGER,                -- filled once the server confirms
    error_reason  TEXT,                   -- filled if the server rejected it
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_outbox_drain ON sync_outbox (status, voucher_date, seq);

-- The local Day-Book mirror: local-only (provisional) vouchers + server-confirmed
-- ones pulled down. provisional=1 means "entered here, not yet on the server".
CREATE TABLE IF NOT EXISTS local_vouchers (
    local_id       INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id      INTEGER,
    client_uuid    TEXT,
    type           TEXT,
    date           TEXT,
    number         INTEGER,
    display_number TEXT,
    narration      TEXT,
    amount         REAL,
    provisional    INTEGER NOT NULL DEFAULT 1,
    updated_at     TEXT
);
CREATE INDEX IF NOT EXISTS idx_local_vouchers_date ON local_vouchers (date);
CREATE UNIQUE INDEX IF NOT EXISTS idx_local_vouchers_server ON local_vouchers (server_id);

-- Small key/value store: the pull cursor, last-sync time, active subdomain,
-- and the masters snapshot (JSON) used to render the offline entry form.
CREATE TABLE IF NOT EXISTS sync_meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);
