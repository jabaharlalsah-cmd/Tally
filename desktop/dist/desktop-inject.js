/* =========================================================================
   ZeroBook Desktop — injected runtime (Phase 7C)
   Embedded by the Rust shell and run BEFORE every page loads, including the
   remote tenant app. It (1) marks the desktop runtime, (2) runs the local-first
   sync worker on tenant pages, (3) shows a keyboard-accessible sync indicator,
   and (4) bridges offline voucher entry into the local SQLite outbox.

   It uses raw Tauri IPC (window.__TAURI__.core.invoke) so it needs no bundled
   plugin packages — it can run inside the server-rendered tenant page as-is.
   ========================================================================= */
(function () {
    'use strict';
    if (window.__ZB_INJECTED__) return;
    window.__ZB_INJECTED__ = true;
    window.ZB_DESKTOP = true;

    var TAURI = window.__TAURI__;
    if (!TAURI || !TAURI.core) return; // not in the desktop shell — nothing to do
    var invoke = function (cmd, args) { return TAURI.core.invoke(cmd, args); };

    var DB = 'sqlite:zerobook_local.db';
    var sql = {
        execute: function (q, v) { return invoke('plugin:sql|execute', { db: DB, query: q, values: v || [] }); },
        select: function (q, v) { return invoke('plugin:sql|select', { db: DB, query: q, values: v || [] }); },
    };

    var SYNC_INTERVAL_MS = 30000; // configurable
    var state = { online: navigator.onLine, pending: 0, errors: 0, lastSyncedAt: null, syncing: false };

    // ---- helpers ---------------------------------------------------------
    function isTenantPage() {
        // A Laravel/Livewire tenant page carries a CSRF meta; the local shell does not.
        return !!document.querySelector('meta[name="csrf-token"]');
    }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function nowIso() { return new Date().toISOString(); }
    function uuid() {
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0, v = c === 'x' ? r : (r & 0x3) | 0x8; return v.toString(16);
        });
    }

    // ---- offline entry bridge (called by the voucher screen when offline) --
    // Queues a voucher into the local outbox + a provisional Day-Book row, so
    // entry works with zero latency and no network. Returned shape mirrors the
    // server's post() result closely enough for the entry loop to continue.
    async function postVoucherLocal(payload) {
        var id = uuid();
        var date = payload.date || nowIso().slice(0, 10);
        var dr = (payload.lines || []).filter(function (l) { return l.dr_cr === 'Dr'; })
            .reduce(function (s, l) { return s + (parseFloat(l.amount) || 0); }, 0);
        await sql.execute(
            'INSERT INTO sync_outbox (client_uuid, entity, payload, voucher_date, seq, status, created_at) VALUES (?,?,?,?,?,?,?)',
            [id, 'voucher', JSON.stringify(payload), date, Date.now(), 'pending', nowIso()]
        );
        await sql.execute(
            'INSERT INTO local_vouchers (client_uuid, type, date, narration, amount, provisional, updated_at) VALUES (?,?,?,?,?,1,?)',
            [id, payload.type, date, payload.narration || null, dr, nowIso()]
        );
        await refreshCounts();
        // A provisional acknowledgement — the voucher is safely queued.
        return { voucher: { id: null, client_uuid: id, provisional: true, number: '(pending)', date: date, type: payload.type, amount: dr }, provisional: true };
    }

    // ---- outbox drain → server (chronological, server-authoritative) ------
    async function drainOutbox() {
        if (!isTenantPage()) return;
        var rows = await sql.select("SELECT * FROM sync_outbox WHERE status='pending' ORDER BY voucher_date ASC, seq ASC");
        if (!rows.length) return;

        var entries = rows.map(function (r) {
            return { client_uuid: r.client_uuid, seq: r.seq, payload: JSON.parse(r.payload) };
        });
        // The SERVER re-sorts by voucher_date too, but we send in order for clarity.
        var resp = await fetch('/api/sync/push', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ entries: entries }),
        });
        if (!resp.ok) throw new Error('push failed: HTTP ' + resp.status);
        var data = await resp.json();

        for (var i = 0; i < data.results.length; i++) {
            var res = data.results[i];
            if (res.status === 'posted') {
                await sql.execute('UPDATE sync_outbox SET status=?, server_id=?, error_reason=NULL WHERE client_uuid=?', ['posted', res.server_id, res.client_uuid]);
                await sql.execute('UPDATE local_vouchers SET server_id=?, number=?, provisional=0, updated_at=? WHERE client_uuid=?', [res.server_id, res.number, nowIso(), res.client_uuid]);
            } else {
                // Server rejected it — keep the local entry, surface the reason.
                await sql.execute('UPDATE sync_outbox SET status=?, error_reason=? WHERE client_uuid=?', ['error', res.reason || 'Rejected by server', res.client_uuid]);
            }
        }
    }

    // ---- pull server-side changes into the local mirror -------------------
    async function pullChanges() {
        if (!isTenantPage()) return;
        var cursorRow = await sql.select("SELECT value FROM sync_meta WHERE key='pull_cursor'");
        var since = cursorRow.length ? parseInt(cursorRow[0].value, 10) || 0 : 0;
        var resp = await fetch('/api/sync/pull?since=' + since, {
            credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!resp.ok) throw new Error('pull failed: HTTP ' + resp.status);
        var data = await resp.json();

        var list = data.type === 'snapshot' ? data.vouchers : (data.vouchers || []).map(function (c) { return c.voucher || c; });
        for (var i = 0; i < list.length; i++) {
            var v = list[i];
            if (!v || !v.id) continue;
            await sql.execute(
                'INSERT INTO local_vouchers (server_id, type, date, number, display_number, narration, amount, provisional, updated_at) VALUES (?,?,?,?,?,?,?,0,?) ' +
                'ON CONFLICT(server_id) DO UPDATE SET type=excluded.type, date=excluded.date, number=excluded.number, display_number=excluded.display_number, narration=excluded.narration, amount=excluded.amount, provisional=0, updated_at=excluded.updated_at',
                [v.id, v.type, v.date, v.number, v.display_number, v.narration, v.amount, nowIso()]
            );
        }
        if (data.type === 'snapshot' && data.masters) {
            await sql.execute("INSERT INTO sync_meta (key, value) VALUES ('masters', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", [JSON.stringify(data.masters)]);
        }
        var cursor = data.cursor || since;
        await sql.execute("INSERT INTO sync_meta (key, value) VALUES ('pull_cursor', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", [String(cursor)]);
    }

    async function refreshCounts() {
        try {
            var p = await sql.select("SELECT COUNT(*) AS n FROM sync_outbox WHERE status='pending'");
            var e = await sql.select("SELECT COUNT(*) AS n FROM sync_outbox WHERE status='error'");
            state.pending = p.length ? p[0].n : 0;
            state.errors = e.length ? e[0].n : 0;
        } catch (_) { /* db not ready */ }
        renderIndicator();
    }

    async function syncCycle() {
        if (state.syncing || !state.online || !isTenantPage()) { renderIndicator(); return; }
        state.syncing = true; renderIndicator();
        try {
            await drainOutbox();
            await pullChanges();
            state.lastSyncedAt = Date.now();
        } catch (err) {
            // stay quiet on transient network errors; the indicator shows offline/pending
        } finally {
            state.syncing = false;
            await refreshCounts();
        }
    }

    // ---- sync indicator (keyboard-accessible) -----------------------------
    function renderIndicator() {
        if (!isTenantPage()) return;
        var el = document.getElementById('zb-sync-indicator');
        if (!el) {
            el = document.createElement('button');
            el.id = 'zb-sync-indicator';
            el.type = 'button';
            el.setAttribute('accesskey', 'y'); // Alt+Y focuses it (keyboard-accessible)
            el.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:99999;border:0;border-radius:20px;padding:6px 12px;font:600 12px Inter,sans-serif;color:#fff;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.25)';
            el.addEventListener('click', function () { syncCycle(); });
            document.body.appendChild(el);
        }
        var bg, txt;
        if (!state.online) { bg = '#97590a'; txt = 'Offline — ' + state.pending + ' pending'; }
        else if (state.errors > 0) { bg = '#b23b32'; txt = 'Sync error — ' + state.errors + ' rejected'; }
        else if (state.syncing) { bg = '#0B6E4F'; txt = 'Syncing…'; }
        else if (state.pending > 0) { bg = '#0B6E4F'; txt = 'Online — ' + state.pending + ' pending'; }
        else {
            bg = '#0B6E4F';
            var ago = state.lastSyncedAt ? Math.round((Date.now() - state.lastSyncedAt) / 1000) : null;
            txt = 'Online — ' + (ago === null ? 'synced' : 'synced ' + ago + 's ago');
        }
        el.style.background = bg;
        el.textContent = txt;
        el.setAttribute('aria-label', txt + '. Press to sync now.');
    }

    // ---- public bridge the voucher screen uses when offline ---------------
    window.zbDesktop = {
        version: window.__ZB_DESKTOP_VERSION__ || '',
        isOffline: function () { return !navigator.onLine; },
        postVoucher: postVoucherLocal,
        syncNow: syncCycle,
        pendingCount: function () { return state.pending; },
        errorCount: function () { return state.errors; },
    };

    // ---- lifecycle --------------------------------------------------------
    window.addEventListener('online', function () { state.online = true; renderIndicator(); syncCycle(); });
    window.addEventListener('offline', function () { state.online = false; renderIndicator(); });

    // Native menu events (from the Rust shell).
    if (TAURI.event) {
        TAURI.event.listen('zb://menu/signout', function () {
            var acct = location.hostname.split('.')[0];
            invoke('clear_token', { account: acct }).finally(function () {
                fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf() } })
                    .finally(function () { location.href = 'index.html'; });
            });
        });
        TAURI.event.listen('zb://menu/about', function () {
            alert('ZeroBook Desktop\nVersion ' + (window.__ZB_DESKTOP_VERSION__ || '1.0.0') + '\nKeyboard-first accounting.');
        });
        TAURI.event.listen('zb://menu/check-update', async function () {
            try {
                var updater = window.__TAURI__.updater;
                if (updater && updater.check) {
                    var update = await updater.check();
                    if (update && update.available) {
                        if (confirm('Update ' + update.version + ' is available. Install and restart?')) {
                            await update.downloadAndInstall();
                            await window.__TAURI__.process.relaunch();
                        }
                    } else { alert('ZeroBook Desktop is up to date.'); }
                } else { alert('Update check is unavailable in this build.'); }
            } catch (e) { alert('Could not check for updates: ' + e); }
        });
    }

    // Boot the worker once the tenant page is ready.
    function boot() {
        if (!isTenantPage()) return;
        refreshCounts();
        syncCycle();
        setInterval(syncCycle, SYNC_INTERVAL_MS);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
