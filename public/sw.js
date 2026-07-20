/* =========================================================================
   ZeroBook service worker — deliberately minimal.

   Its ONLY job is to make the app installable, so the browser will run it in
   standalone mode. Standalone is what reclaims the Tally keys a browser tab
   keeps for itself (F11, F12, Ctrl+T, Alt+D) — see the shortcut doc §F.1.

   It caches NOTHING, on purpose:

     - ZeroBook is a Livewire app. Cached HTML would be served with a stale
       CSRF token and a stale Livewire snapshot, producing page-expired errors
       that look like data loss to an operator mid-voucher.
     - ZeroBook Desktop already runs its own offline layer against local
       SQLite. A second, disagreeing offline layer is worse than none — which
       is also why registration is skipped entirely inside the desktop shell
       (see app.js).
     - It is multi-tenant by subdomain. Cross-tenant cache bleed is a data
       confidentiality problem, not merely a correctness one.

   If genuine offline support is wanted later it belongs behind an explicit
   decision about tenancy and staleness, not bolted on here.
   ========================================================================= */

self.addEventListener('install', () => {
    // Take over immediately rather than waiting for every tab to close, so an
    // updated worker never lingers behind an old one.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // Clear anything a previous iteration of this worker may have
            // cached, so upgrading can never leave stale app shell behind.
            const names = await caches.keys();
            await Promise.all(names.map((n) => caches.delete(n)));
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', (event) => {
    // A fetch handler must exist for the browser to treat the app as
    // installable. Pass straight through — the network is the only source of
    // truth here.
    event.respondWith(fetch(event.request));
});
