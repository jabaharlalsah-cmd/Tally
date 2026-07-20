{{--
    Phase 16A — the public API documentation stub.

    Served at /docs/api on the central domain. The brief names docs.zerobook.local; a dedicated
    docs host is a DNS + vhost change rather than an application one ('docs' is already a
    reserved subdomain, so that hostname stays available), and the brief allows "the equivalent
    central-app URL". This page is that URL and moves unchanged if the host is added later.

    Generated OpenAPI reference lands in 16E; until then this is hand-written and deliberately
    small — it documents only what actually exists today.
--}}
<x-layouts.plain title="ZeroBook API — Documentation">
    <x-slot:topRight>
        <a href="{{ route('landing') }}">← zerobook</a>
    </x-slot:topRight>

    <style>
        .doc code, .doc pre { font-family: ui-monospace, Menlo, Consolas, monospace; }
        .doc pre { background: #101b17; color: #e6f2ec; border-radius: 10px; padding: 1rem 1.1rem;
            overflow-x: auto; font-size: .84rem; line-height: 1.55; }
        .doc pre .c { color: #7fd8b4; }
        .doc h2 { font-family: Spectral, Georgia, serif; font-size: 1.1rem; margin: 2rem 0 .5rem; }
        .doc h3 { font-size: .92rem; margin: 1.3rem 0 .35rem; }
        .doc p { font-size: .92rem; }
        .doc table { margin-top: .6rem; }
        .doc code.inline { background: #f2f6f4; border: 1px solid var(--line); border-radius: 5px;
            padding: .08rem .32rem; font-size: .84rem; }
        .doc .note { background: #fff4e0; color: #97590a; border: 1px solid #f3d9a8;
            border-radius: 8px; padding: .7rem .85rem; font-size: .86rem; margin: 1rem 0; }
    </style>

    <div class="zb-card doc">
        <h1 style="font-size:1.4rem">ZeroBook API</h1>
        <p class="sub">
            Connect your own website or software to a ZeroBook account — post transactions as they
            happen, and read your books back.
        </p>

        <p>
            <span class="pill pill-active">v1 · live</span>
            <span class="pill pill-prov" style="margin-left:.3rem">business endpoints coming soon</span>
        </p>

        <div class="note">
            <strong>API v1 is live.</strong> Authentication, permissions and rate limiting are in
            place, and <code class="inline">GET /api/v1/ping</code> works today so you can verify a
            key end to end. Endpoints for vouchers, masters and reports arrive in the next release.
        </div>

        <h2>Authentication</h2>
        <p>
            Every request carries an API key as a bearer token. An account owner issues keys from
            <strong>Settings → API Keys</strong> inside ZeroBook.
        </p>

        <pre><span class="c"># every request looks like this</span>
curl https://your-account.zerobook.in/api/v1/ping \
  -H "Authorization: Bearer zb_live_your_key_here"</pre>

        <p>
            A key looks like <code class="inline">zb_live_…</code> and is shown to you exactly once,
            when it is created. ZeroBook stores only a one-way hash of it — we cannot recover or
            re-display it. If a key is lost or leaked, revoke it and issue a new one.
        </p>

        <h3>What a key carries</h3>
        <p>
            A key belongs to one account and is authorized for specific companies within it, with a
            specific set of permissions. Both are enforced on every request — a read-only key
            cannot write, whatever it asks for.
        </p>

        <h3>Choosing a company</h3>
        <p>
            If a key is authorized for more than one company, name the one you mean with the
            <code class="inline">X-Company-Id</code> header. Omit it and the key's first authorized
            company is used. Requesting a company the key is not authorized for returns
            <code class="inline">403</code>.
        </p>

        <h2>Try it — the ping endpoint</h2>
        <p>
            <code class="inline">GET /api/v1/ping</code> needs no permission beyond a valid key. Use
            it to confirm a key works, and that it points at the account and company you expect,
            before wiring up anything else.
        </p>

        <pre>{
  <span class="c">"tenant"</span>: "your-account",
  <span class="c">"company"</span>: "head-office",
  <span class="c">"key_name"</span>: "HMS Production",
  <span class="c">"server_time"</span>: "2026-07-16T08:04:23+00:00"
}</pre>

        <h2>Errors</h2>
        <p>Every error has the same shape. Branch on <code class="inline">code</code>, show <code class="inline">message</code>.</p>

        <pre>{
  <span class="c">"error"</span>: {
    <span class="c">"code"</span>: "insufficient_scope",
    <span class="c">"message"</span>: "This API key does not have the permission required for this endpoint.",
    <span class="c">"details"</span>: { "required": "voucher:create" }
  }
}</pre>

        <table>
            <thead><tr><th>Status</th><th>Code</th><th>What it means</th></tr></thead>
            <tbody>
                <tr><td>401</td><td><code>invalid_key</code></td><td>Missing, malformed, or unrecognised key.</td></tr>
                <tr><td>401</td><td><code>key_revoked</code></td><td>The key was revoked or has expired. Issue a new one.</td></tr>
                <tr><td>403</td><td><code>tenant_not_active</code></td><td>The account is suspended or expired. API access resumes on reactivation.</td></tr>
                <tr><td>403</td><td><code>insufficient_scope</code></td><td>The key lacks the permission this endpoint needs.</td></tr>
                <tr><td>403</td><td><code>company_not_authorized</code></td><td>The key is not authorized for the requested company.</td></tr>
                <tr><td>429</td><td><code>rate_limited</code></td><td>Too many requests. Wait for <code>Retry-After</code> seconds.</td></tr>
                <tr><td>422</td><td><code>validation_failed</code></td><td>The payload was rejected. See <code>details</code>.</td></tr>
                <tr><td>500</td><td><code>internal_error</code></td><td>Our fault. Quote the <code>error_id</code> to support.</td></tr>
            </tbody>
        </table>

        <h2>Rate limits</h2>
        <p>
            Keys are limited to <strong>{{ config('zerobook.api.rate_limit_per_min', 60) }} requests per minute</strong>
            by default. Every response reports where you stand:
        </p>

        <pre>X-RateLimit-Limit: {{ config('zerobook.api.rate_limit_per_min', 60) }}
X-RateLimit-Remaining: 57
Retry-After: 34          <span class="c"># only when limited</span></pre>

        <p>
            If your integration needs a higher ceiling — a nightly batch, say — set a per-key limit
            when you issue the key, or ask support.
        </p>

        <h2>Tracing a problem</h2>
        <p>
            Every response carries an <code class="inline">X-Request-Id</code> header. Quote it when
            you contact support and we can find the exact request. You can also see your recent
            calls, and their ids, under <strong>Settings → API Keys → Activity</strong>.
        </p>

        <h2>Business endpoints (v1)</h2>
        <p>All paths are under <code class="inline">/api/v1</code>. Each needs the permission shown.</p>

        <h3>Vouchers</h3>
        <table>
            <thead><tr><th>Method</th><th>Path</th><th>Permission</th><th>What</th></tr></thead>
            <tbody>
                <tr><td>POST</td><td><code>/vouchers</code></td><td><code>voucher:create</code></td><td>Post a sale, purchase, receipt, payment, journal, contra, or note.</td></tr>
                <tr><td>GET</td><td><code>/vouchers/{id}</code></td><td><code>voucher:read</code></td><td>One voucher with its lines, items, and bill allocations.</td></tr>
                <tr><td>GET</td><td><code>/vouchers</code></td><td><code>voucher:read</code></td><td>List, filter by <code>type/from/to/party_ledger_id</code>, cursor-paged.</td></tr>
                <tr><td>PUT</td><td><code>/vouchers/{id}</code></td><td><code>voucher:alter</code></td><td>Replace a voucher (full payload).</td></tr>
                <tr><td>POST</td><td><code>/vouchers/{id}/cancel</code></td><td><code>voucher:cancel</code></td><td>Cancel a voucher. Idempotent.</td></tr>
            </tbody>
        </table>

        <h3>Masters &amp; reports</h3>
        <table>
            <thead><tr><th>Method</th><th>Path</th><th>Permission</th></tr></thead>
            <tbody>
                <tr><td>GET/POST</td><td><code>/ledgers</code>, <code>/ledgers/{id}</code> (GET/PUT)</td><td><code>master:read</code> / <code>master:write</code></td></tr>
                <tr><td>GET/POST</td><td><code>/stock-items</code>, <code>/stock-items/{id}</code> (GET/PUT)</td><td><code>master:read</code> / <code>master:write</code></td></tr>
                <tr><td>GET</td><td><code>/reports/trial-balance?as_of=</code></td><td><code>report:read</code></td></tr>
                <tr><td>GET</td><td><code>/reports/ledger-balance/{ledger}?as_of=</code></td><td><code>report:read</code></td></tr>
                <tr><td>GET</td><td><code>/reports/party-outstanding/{ledger}?as_of=</code></td><td><code>report:read</code></td></tr>
                <tr><td>GET</td><td><code>/reports/day-book?date=</code></td><td><code>report:read</code></td></tr>
            </tbody>
        </table>

        <h2>Money</h2>
        <p>
            Send and read amounts as <strong>decimal strings</strong> — <code class="inline">"1500.00"</code>,
            not <code class="inline">1500.00</code>. A JSON number is accepted but risky: large or
            high-precision values lose bits before we see them. Internally everything is exact integer
            paise, so a string round-trips without error.
        </p>

        <h2>Tax is computed on the server</h2>
        <p>
            You do <em>not</em> send GST/VAT or TDS amounts. Post the taxable lines (or item lines) and
            the party, and ZeroBook computes the tax from your company's regime, the party's state, and
            the ledger/item rates. Send an optional <code class="inline">expected_total</code> and we
            reject a mismatch before anything is posted — so a rounding disagreement never silently
            books the wrong figure.
        </p>

        <pre><span class="c"># a GST sales invoice — tax legs are added by the server</span>
curl -X POST https://your-account.zerobook.in/api/v1/vouchers \
  -H "Authorization: Bearer zb_live_…" \
  -H "Idempotency-Key: inv-2026-000842" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "sales",
    "date": "2026-07-15",
    "party_ledger_id": 42,
    "reference_no": "INV-842",
    "items": [{"stock_item_id": 5, "qty": "2", "rate": "500.00"}],
    "revenue_ledger_id": 11,
    "bill_allocations": [{"ref_type": "new", "ref_name": "INV-842", "amount": "1180.00", "due_date": "2026-08-15"}],
    "expected_total": "1180.00"
  }'</pre>

        <h2>Idempotency — required on every write</h2>
        <p>
            Every <code class="inline">POST</code>/<code class="inline">PUT</code>/cancel needs an
            <code class="inline">Idempotency-Key</code> header: any opaque string you choose, up to 255
            characters, unique per logical operation. It is how a retry after a network hiccup does not
            bill your customer twice.
        </p>
        <ul style="font-size:.9rem">
            <li><strong>Same key, same body</strong> → you get the original response again, with
                <code class="inline">X-Idempotency-Replay: true</code>. Exactly one voucher exists.</li>
            <li><strong>Same key, different body</strong> → <code class="inline">409 idempotency_key_reused</code>.</li>
            <li><strong>Missing key on a write</strong> → <code class="inline">422 idempotency_key_required</code>.</li>
        </ul>

        <pre><span class="c"># receive a payment against an invoice (Against Ref)</span>
curl -X POST https://your-account.zerobook.in/api/v1/vouchers \
  -H "Authorization: Bearer zb_live_…" \
  -H "Idempotency-Key: rcpt-2026-000191" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "receipt",
    "date": "2026-07-16",
    "party_ledger_id": 42,
    "bank_ledger_id": 3,
    "amount": "1180.00",
    "bill_allocations": [{"ref_type": "against", "ref_name": "INV-842", "amount": "1180.00"}]
  }'</pre>

        <h2>Choosing a company &amp; reading back</h2>
        <pre><span class="c"># read a party's outstanding bills</span>
curl "https://your-account.zerobook.in/api/v1/reports/party-outstanding/42?as_of=2026-07-31" \
  -H "Authorization: Bearer zb_live_…" \
  -H "X-Company-Id: 1"</pre>

        <h2>Webhooks — we tell you, instead of you asking</h2>
        <p>
            Register an endpoint under <strong>Settings → Webhooks</strong> (or via the API with the
            <code class="inline">webhook:manage</code> permission) and ZeroBook POSTs a signed JSON
            event to it the moment something happens — no polling.
        </p>
        <p>
            <strong>A webhook never reaches further than the key that made it.</strong> If your API
            key is limited to certain companies, a webhook it creates is limited to the same ones —
            <code class="inline">authorized_company_ids</code> defaults to your key's companies, and
            naming a company your key cannot read is rejected with
            <code class="inline">403 company_not_authorized</code>. Only a key authorized for every
            company can create an all-companies webhook (<code class="inline">[]</code>). For the
            same reason, a key sees and manages only the webhooks that fall within its own
            companies; the others answer <code class="inline">404</code>.
        </p>

        <h3>Events</h3>
        <table>
            <thead><tr><th>Event</th><th>Fires when</th></tr></thead>
            <tbody>
                <tr><td><code>voucher.created</code></td><td>A voucher is posted (any type). Payload: the voucher, same shape as <code>GET /vouchers/{id}</code>.</td></tr>
                <tr><td><code>voucher.altered</code></td><td>A voucher is altered. Payload: the updated voucher.</td></tr>
                <tr><td><code>voucher.cancelled</code></td><td>A voucher is cancelled. Payload: the voucher as it was, plus <code>status: cancelled</code>.</td></tr>
                <tr><td><code>payment.recorded</code></td><td>A receipt or payment voucher is posted <em>in your books</em>.</td></tr>
                <tr><td><code>party.outstanding.changed</code></td><td>A party's bill-wise outstanding moves. Payload: party, previous and new outstanding.</td></tr>
                <tr><td><code>ping</code></td><td>You pressed "Send test".</td></tr>
                <tr><td><code>*</code></td><td>Subscribe to everything, including events we add later.</td></tr>
            </tbody>
        </table>

        <p>Every event has the same envelope:</p>
        <pre>{
  <span class="c">"event"</span>: "voucher.created",
  <span class="c">"event_id"</span>: "01J8Z…",          <span class="c">// stable across retries — dedupe on this</span>
  <span class="c">"company_id"</span>: 1,
  <span class="c">"occurred_at"</span>: "2026-07-15T10:04:23+00:00",
  <span class="c">"data"</span>: { … the resource … }
}</pre>

        <h3>Verifying the signature — do this before you trust an event</h3>
        <p>
            Every delivery carries these headers. The signature is
            <code class="inline">HMAC-SHA256(secret, "&lt;timestamp&gt;.&lt;raw body&gt;")</code> — the
            timestamp is <em>inside</em> the signature, so nobody can replay yesterday's event with a
            fresh clock.
        </p>
        <pre>X-ZeroBook-Event:      voucher.created
X-ZeroBook-Event-Id:   01J8Z…        <span class="c"># same across every retry</span>
X-ZeroBook-Delivery:   01J8Z…        <span class="c"># unique per attempt</span>
X-ZeroBook-Timestamp:  1784192348
X-ZeroBook-Signature:  sha256=9f86d081…</pre>

        <p>In PHP:</p>
        <pre><span class="c">// $secret is the value ZeroBook showed you once, when you created the webhook.</span>
$body      = file_get_contents('php://input');
$timestamp = (int) $_SERVER['HTTP_X_ZEROBOOK_TIMESTAMP'];
$signature = $_SERVER['HTTP_X_ZEROBOOK_SIGNATURE'];

<span class="c">// 1. Refuse anything older than 5 minutes — that is your replay defence.</span>
if (abs(time() - $timestamp) > 300) { http_response_code(400); exit; }

<span class="c">// 2. Recompute and compare in CONSTANT TIME (never ===).</span>
$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
if (! hash_equals($expected, $signature)) { http_response_code(401); exit; }

<span class="c">// 3. Dedupe — delivery is at-least-once, so you WILL see repeats.</span>
$event = json_decode($body, true);
if (already_processed($event['event_id'])) { http_response_code(200); exit; }

process($event);
http_response_code(200);   <span class="c">// any 2xx = delivered</span></pre>

        <p>In any language:</p>
        <pre>signed  = timestamp + "." + raw_body
expected = "sha256=" + hex(hmac_sha256(secret, signed))
reject unless constant_time_equals(expected, header_signature)
reject unless abs(now - timestamp) &lt;= 300
dedupe on event_id
respond 2xx</pre>

        <div class="note">
            <strong>Read the raw body, not a re-encoded one.</strong> Sign-and-verify is byte-exact:
            if your framework parses the JSON and you re-serialise it, the bytes change and the
            signature will not match. Capture the body before any middleware touches it.
        </div>

        <h3>Retries, and what we expect back</h3>
        <p>
            Answer with any <strong>2xx</strong> as soon as you have stored the event — do the work
            afterwards. Anything else (or a timeout past 10 seconds) is a failure and we retry:
        </p>
        <pre>attempt 1  immediately
attempt 2  +5 seconds
attempt 3  +30 seconds
attempt 4  +5 minutes
attempt 5  +30 minutes
attempt 6  +3 hours      <span class="c"># then the delivery is exhausted</span></pre>
        <p>
            Every retry carries the <strong>same</strong> <code class="inline">event_id</code> and the
            payload as it was when the event happened — so a retry can never contradict what you
            already stored. If an endpoint keeps failing, we turn it off and email the account owner;
            re-enable it under Settings → Webhooks once it is healthy.
        </p>

        <div class="note" style="background:#e8f6ef; color:var(--zb-dark); border-color:#b8e2cf">
            <strong>Coming next.</strong> Inbound event ingestion — your site telling ZeroBook when
            something happens, verified with this same signature scheme (16D); and a full generated
            OpenAPI reference plus a polling fallback (16E).
        </div>
    </div>
</x-layouts.plain>
