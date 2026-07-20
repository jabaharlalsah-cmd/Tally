/* ZeroBook Desktop — the launcher shell (Phase 7C).
   Tenant picker → navigate the window to the tenant's ZeroBook app. The tenant's
   session is the webview's persisted cookie; the OS keychain remembers which
   companies are set up (cleared on Sign Out). No accounting logic here. */
(function () {
    'use strict';
    var TAURI = window.__TAURI__;
    var invoke = TAURI && TAURI.core ? TAURI.core.invoke : function () { return Promise.resolve(); };

    var LIST_KEY = 'zb_tenants'; // [{host, name}] — which companies (not secret)

    function loadTenants() {
        try { return JSON.parse(localStorage.getItem(LIST_KEY) || '[]'); } catch (_) { return []; }
    }
    function saveTenants(list) { localStorage.setItem(LIST_KEY, JSON.stringify(list)); }

    function normalizeHost(input) {
        var h = (input || '').trim().replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
        return h.toLowerCase();
    }
    function appUrl(host) {
        var isLocal = /^(localhost|127\.0\.0\.1)(:\d+)?$/.test(host) || /\.local(:\d+)?$/.test(host);
        return (isLocal ? 'http://' : 'https://') + host + '/app';
    }

    function showError(msg) {
        var e = document.getElementById('err');
        e.textContent = msg; e.hidden = !msg;
    }

    function render() {
        var list = loadTenants();
        var ul = document.getElementById('tenant-list');
        ul.innerHTML = '';
        if (!list.length) {
            var li = document.createElement('li');
            li.className = 'empty';
            li.textContent = 'No companies yet — add one below.';
            ul.appendChild(li);
            return;
        }
        list.forEach(function (t) {
            var li = document.createElement('li');
            li.className = 'tenant';

            var open = document.createElement('button');
            open.className = 'tenant-open';
            open.type = 'button';
            open.onclick = function () { openTenant(t.host); };
            open.innerHTML = '<strong>' + (t.name || t.host.split('.')[0]) + '</strong><span>' + t.host + '</span>';

            var rm = document.createElement('button');
            rm.className = 'tenant-remove';
            rm.type = 'button';
            rm.title = 'Forget this company';
            rm.setAttribute('aria-label', 'Forget ' + t.host);
            rm.textContent = '×';
            rm.onclick = function () { forget(t.host); };

            li.appendChild(open);
            li.appendChild(rm);
            ul.appendChild(li);
        });
    }

    async function openTenant(host) {
        host = normalizeHost(host);
        if (!host || host.indexOf('.') === -1 && !/^localhost/.test(host)) {
            showError('Enter a valid company address, e.g. acme.zerobook.io');
            return;
        }
        var list = loadTenants();
        if (!list.some(function (t) { return t.host === host; })) {
            list.push({ host: host, name: host.split('.')[0] });
            saveTenants(list);
        }
        // Remember the company in the OS keychain (cleared on Sign Out).
        try { await invoke('store_token', { account: host, token: JSON.stringify({ rememberedAt: Date.now() }) }); } catch (_) {}
        // Navigate the window to the tenant app. If the session has lapsed, the app
        // itself redirects to /login (the tenant login page shown in this window).
        window.location.href = appUrl(host);
    }

    async function forget(host) {
        var list = loadTenants().filter(function (t) { return t.host !== host; });
        saveTenants(list);
        try { await invoke('clear_token', { account: host }); } catch (_) {}
        render();
    }

    document.getElementById('add-form').addEventListener('submit', function (e) {
        e.preventDefault();
        openTenant(document.getElementById('host').value);
    });

    invoke('app_version').then(function (v) {
        var el = document.getElementById('version'); if (el && v) el.textContent = 'v' + v;
    }).catch(function () {});

    render();
})();
