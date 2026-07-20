<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnforceApiPermissions;
use App\Http\Middleware\IdentifyTenantByApiKey;
use App\Http\Middleware\LogApiRequest;
use App\Livewire\ApiKeysList;
use App\Models\ApiKey;
use App\Models\ApiKeyDirectory;
use App\Models\ApiRequestLog;
use App\Models\Company;
use App\Models\Tenant;
use App\Services\Api\ApiKeyService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use App\Support\ApiError;
use App\Support\ApiScopes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Phase 16A — prove the API foundation.
 *
 * THIS PROOF DISPATCHES REAL HTTP REQUESTS, and that is the whole point.
 *
 * Every prior prove-* command asserts against services and components directly; not one has ever
 * executed a middleware stack. The single HTTP-ish precedent builds a Request and hands it
 * straight to a controller method — the router and every middleware are bypassed. 16A is a
 * MIDDLEWARE phase: authentication, tenant isolation, company scoping, scope enforcement, rate
 * limiting and logging ALL live in middleware. Copying that precedent would call PingController
 * directly, assert 200, and prove exactly nothing — a completely unregistered middleware chain
 * would still show ALL PASS.
 *
 * So this command drives requests through the real HTTP kernel:
 *
 *     app(HttpKernel::class)->handle(Request::create('/api/v1/ping', 'GET', …))
 *
 * No web server, no phpunit — the kernel is bound and resolvable in console context. What runs
 * is what production runs: the same router, the same sorted middleware, the same responses.
 *
 * The isolation and constant-time sections are the ones that matter most: those are the bugs
 * that pass every test, ship, and get exploited quietly.
 */
class ProveApiFoundationCommand extends Command
{
    protected $signature = 'zerobook:prove-api-foundation {--keep : keep the throwaway api tenants provisioned}';

    protected $description = 'Provision tenants, issue API keys, and prove the 16A API foundation end-to-end over real HTTP';

    private bool $ok = true;

    /** Tenant A is multi-company; tenant B exists solely to prove A cannot reach it. */
    private array $slugs = ['apia', 'apib'];

    public function handle(TenantProvisioner $provisioner, ApiKeyService $keys): int
    {
        try {
            $this->section('1 · Provision two tenants');

            foreach ($this->slugs as $slug) {
                $provisioner->teardown($slug);   // start clean even if a prior run crashed
                $t = $provisioner->provision($slug, strtoupper($slug).' Books', 'enterprise');
                $this->expect("provisioned {$slug} → {$t->database()->getName()}", $t->status, 'active');
            }

            $tenantA = Tenant::find('apia');
            $tenantB = Tenant::find('apib');

            // A second company in tenant A — company scoping needs something to scope AGAINST.
            $secondCompanyId = $tenantA->run(function () {
                $c = Company::create([
                    'name' => 'APIA Branch', 'slug' => 'apia-branch',
                    'financial_year_start_month' => 4, 'is_active' => true,
                ]);

                return $c->id;
            });
            $this->expect('tenant apia has a 2nd company', $secondCompanyId > 1, true);

            // ── 2. Issuance ──────────────────────────────────────────────────
            $this->section('2 · Key issuance — hashed, never stored raw');

            $issued = $keys->generate(
                tenant: $tenantA,
                user: null,
                name: 'HMS Integration',
                permissions: ['voucher:create', 'voucher:read', 'master:read'],
                companyIds: [],           // all companies
            );

            $rawKey = $issued['key'];
            $keyRow = $issued['key_row'];

            $this->expect('raw key returned once, zb_live_ + 32', (bool) preg_match('/^zb_live_[A-Za-z0-9]{32}$/', $rawKey), true);
            $this->expect('prefix is plaintext + indexed', $keyRow->prefix, substr($rawKey, 0, 16));
            $this->expect('key_hash is a bcrypt digest, not the raw key', str_starts_with($keyRow->key_hash, '$2y$'), true);
            $this->expect('key_hash !== raw key', $keyRow->key_hash === $rawKey, false);
            $this->expect('password_verify(raw, key_hash) is true', Hash::check($rawKey, $keyRow->key_hash), true);
            $this->expect('scopes stored', $keyRow->scopes(), ['voucher:create', 'voucher:read', 'master:read']);
            $this->expect('authorized_company_ids [] = all companies', $keyRow->authorizesAllCompanies(), true);

            // The raw key must exist in NO column of the row.
            $rowJson = $tenantA->run(fn () => json_encode(ApiKey::find($keyRow->id)->getAttributes()));
            $this->expect('raw key absent from every column of the row', str_contains($rowJson, $rawKey), false);

            $this->expect('central directory routes prefix → tenant',
                ApiKeyDirectory::where('prefix', $keyRow->prefix)->value('tenant_id'), 'apia');

            // ── 3. Ping, authenticated ───────────────────────────────────────
            $this->section('3 · GET /api/v1/ping with a valid key');

            $r = $this->dispatch($rawKey);
            $this->expect('status 200', $r['status'], 200);
            $this->expect('tenant slug', $r['json']['tenant'] ?? null, 'apia');
            $this->expect('company slug', $r['json']['company'] ?? null, 'apia-books');
            $this->expect('key_name', $r['json']['key_name'] ?? null, 'HMS Integration');
            $this->expect('server_time is ISO-8601', (bool) strtotime($r['json']['server_time'] ?? ''), true);
            $this->expect('X-Request-Id header present', (bool) $r['request_id'], true);
            $this->expect('X-RateLimit-Limit header present', $r['headers']->get('X-RateLimit-Limit'), '60');

            // ── 4. The generic-401 family ────────────────────────────────────
            $this->section('4 · Auth failures are generic and indistinguishable');

            $noKey = $this->dispatch(null);
            $this->expect('no auth header → 401', $noKey['status'], 401);
            $this->expect('no auth header → invalid_key', $noKey['json']['error']['code'] ?? null, 'invalid_key');

            $garbage = $this->dispatch('garbage');
            $this->expect('malformed key → 401', $garbage['status'], 401);
            $this->expect('malformed key → invalid_key', $garbage['json']['error']['code'] ?? null, 'invalid_key');

            // A syntactically PERFECT key whose prefix was never issued. If the response differed
            // from the garbage one, it would confirm prefix existence to an attacker.
            $wellFormedUnknown = 'zb_live_'.str_repeat('a', 32);
            $unknown = $this->dispatch($wellFormedUnknown);
            $this->expect('unknown prefix → 401', $unknown['status'], 401);
            $this->expect('unknown prefix → SAME code as garbage', $unknown['json']['error']['code'] ?? null, 'invalid_key');
            $this->expect('unknown-prefix body is byte-identical to garbage body', $unknown['body'], $garbage['body']);

            // A REAL prefix with the wrong secret — the case that most tempts an implementation
            // into a distinguishable response.
            $rightPrefixWrongSecret = $keyRow->prefix.str_repeat('Z', 24);
            $wrongSecret = $this->dispatch($rightPrefixWrongSecret);
            $this->expect('real prefix + wrong secret → 401', $wrongSecret['status'], 401);
            $this->expect('real prefix + wrong secret → body identical to unknown prefix', $wrongSecret['body'], $unknown['body']);

            $this->expect('no response body ever echoes the raw key',
                str_contains($noKey['body'].$garbage['body'].$unknown['body'].$wrongSecret['body'], $rawKey), false);

            // ── 5. Constant-time discipline ──────────────────────────────────
            $this->section('5 · Constant-time verification (no early return on a miss)');

            // EVERY MEASUREMENT BELOW CLEARS THE PER-PROCESS MEMO FIRST. That is not paranoia —
            // it is the difference between this section being a proof and being theatre.
            //
            // This command runs in ONE long-lived console process, so the first authenticate()
            // warms ApiKeyService::$dummyHash and every later call reuses it. PHP-FPM does the
            // opposite: fresh statics on EVERY request. Measuring warm state therefore measures
            // the one scenario production never runs.
            //
            // That gap hid a real 1.98x timing oracle (the memo was always cold in production, so
            // each miss paid Hash::make + Hash::check while a hit paid one Hash::check). The
            // warm-state numbers reported a reassuring 0.99 ratio throughout. So: model the
            // process boundary explicitly, and assert the ratio in BOTH directions.
            $coldStart = function () {
                $memo = new \ReflectionProperty(ApiKeyService::class, 'dummyHash');
                $memo->setAccessible(true);
                $memo->setValue(null, null);
            };

            $timeCold = function (callable $work) use ($coldStart) {
                $coldStart();

                return $this->timeOf($work);
            };

            $hitMs = $timeCold(fn () => $keys->authenticate($rightPrefixWrongSecret));   // found → one real bcrypt
            $missMs = $timeCold(fn () => $keys->authenticate($wellFormedUnknown));       // not found → one dummy bcrypt
            $malformedMs = $timeCold(fn () => $keys->authenticate('garbage'));           // unparseable → one dummy bcrypt

            $ratio = $hitMs > 0 ? $missMs / $hitMs : 0;
            $this->line(sprintf('   ── cold-process: found-prefix %.1fms · unknown-prefix %.1fms · malformed %.1fms (miss/hit %.2f)',
                $hitMs, $missMs, $malformedMs, $ratio));

            // Too FAST = a skipped verify (the classic oracle).
            $this->expect('unknown prefix still pays a bcrypt verify (≥25% of a real one)', $missMs >= $hitMs * 0.25, true);
            $this->expect('malformed key still pays a bcrypt verify (≥25% of a real one)', $malformedMs >= $hitMs * 0.25, true);

            // Too SLOW = the inverted oracle a per-process dummy-hash memo reintroduces. A cold
            // miss paying make+check lands at ~2.0x; the ceiling below fails long before that.
            $this->expect('unknown prefix is not measurably SLOWER either (<1.6x a real one)', $missMs <= $hitMs * 1.6, true);
            $this->expect('malformed key is not measurably SLOWER either (<1.6x a real one)', $malformedMs <= $hitMs * 1.6, true);

            // The mechanism behind it: the dummy digest must survive a cold process, or every
            // production request pays to rebuild it.
            $coldStart();
            $cachedHash = \Illuminate\Support\Facades\Cache::get('zb:api:dummy_hash:'.substr(md5(serialize(config('hashing'))), 0, 12));
            $this->expect('the dummy digest is cached ACROSS processes (not rebuilt per request)',
                is_string($cachedHash) && str_starts_with($cachedHash, '$2y$'), true);

            // ── 6. Revocation ────────────────────────────────────────────────
            $this->section('6 · Revoked key');

            $revokable = $keys->generate($tenantA, null, 'Throwaway', ['voucher:read'], []);
            $this->expect('throwaway key works before revoke', $this->dispatch($revokable['key'])['status'], 200);

            $tenantA->run(fn () => $keys->revoke(ApiKey::find($revokable['key_row']->id), null));

            $revoked = $this->dispatch($revokable['key']);
            $this->expect('revoked key → 401', $revoked['status'], 401);
            $this->expect('revoked key → key_revoked', $revoked['json']['error']['code'] ?? null, 'key_revoked');
            $this->expect('revoke is a soft delete (row survives)',
                $tenantA->run(fn () => ApiKey::find($revokable['key_row']->id)?->revoked_at !== null), true);

            // Expiry must behave exactly like revocation.
            $expiring = $keys->generate($tenantA, null, 'Expired', ['voucher:read'], [], now()->subDay());
            $expired = $this->dispatch($expiring['key']);
            $this->expect('expired key → 401 key_revoked', $expired['json']['error']['code'] ?? null, 'key_revoked');

            // ── 7. Cross-tenant isolation ────────────────────────────────────
            $this->section('7 · Cross-tenant isolation');

            $keyB = $keys->generate($tenantB, null, 'B Key', ['voucher:read'], []);

            $pingA = $this->dispatch($rawKey);
            $pingB = $this->dispatch($keyB['key']);

            $this->expect("tenant A's key resolves to apia", $pingA['json']['tenant'] ?? null, 'apia');
            $this->expect("tenant B's key resolves to apib", $pingB['json']['tenant'] ?? null, 'apib');

            // Interleaved — the pattern that catches a memoised connection or a leaked static.
            $seq = [];
            foreach ([$rawKey, $keyB['key'], $rawKey, $keyB['key'], $rawKey] as $k) {
                $seq[] = $this->dispatch($k)['json']['tenant'] ?? null;
            }
            $this->expect('interleaved A/B/A/B/A never crosses over', $seq, ['apia', 'apib', 'apia', 'apib', 'apia']);

            // A's key must never be findable in B's database.
            $this->expect("A's prefix does not exist in tenant B's api_keys",
                $tenantB->run(fn () => ApiKey::where('prefix', $keyRow->prefix)->exists()), false);
            $this->expect("B's prefix does not exist in tenant A's api_keys",
                $tenantA->run(fn () => ApiKey::where('prefix', $keyB['key_row']->prefix)->exists()), false);

            // The request log must land in the OWNING tenant only.
            $this->expect("A's requests logged only in A",
                $tenantB->run(fn () => ApiRequestLog::where('request_id', $pingA['request_id'])->exists()), false);
            $this->expect("A's request IS in A's log",
                $tenantA->run(fn () => ApiRequestLog::where('request_id', $pingA['request_id'])->exists()), true);

            // ── 8. Company scoping ───────────────────────────────────────────
            $this->section('8 · Company scoping');

            $scoped = $keys->generate($tenantA, null, 'Company 1 Only', ['voucher:read'], [1]);

            $omitted = $this->dispatch($scoped['key']);
            $this->expect('omitting X-Company-Id → 200 on company 1', $omitted['status'], 200);
            $this->expect('omitting X-Company-Id → company 1 active', $omitted['json']['company'] ?? null, 'apia-books');

            $explicit = $this->dispatch($scoped['key'], ['X-Company-Id' => '1']);
            $this->expect('X-Company-Id: 1 → 200', $explicit['status'], 200);

            $forbidden = $this->dispatch($scoped['key'], ['X-Company-Id' => (string) $secondCompanyId]);
            $this->expect('X-Company-Id: 2 (unauthorized) → 403', $forbidden['status'], 403);
            $this->expect('… → company_not_authorized', $forbidden['json']['error']['code'] ?? null, 'company_not_authorized');

            // Header-manipulation attempts must not slip past the id check.
            foreach (['1abc' => 'non-numeric suffix', '01' => 'zero-padded', ' 2' => 'whitespace', '1 or 1=1' => 'sql-ish', '-1' => 'negative', '999' => 'nonexistent'] as $probe => $label) {
                $res = $this->dispatch($scoped['key'], ['X-Company-Id' => $probe]);
                $allowedOther = $res['status'] === 200 && ($res['json']['company'] ?? null) !== 'apia-books';
                $this->expect("X-Company-Id '{$probe}' ({$label}) cannot reach another company", $allowedOther, false);
            }

            // An all-companies key may address either one.
            $branch = $this->dispatch($rawKey, ['X-Company-Id' => (string) $secondCompanyId]);
            $this->expect('all-companies key + X-Company-Id: 2 → 200', $branch['status'], 200);
            $this->expect('… → company 2 active', $branch['json']['company'] ?? null, 'apia-branch');

            // ── 9. Tenant lifecycle ──────────────────────────────────────────
            $this->section('9 · Suspended tenant');

            $tenantA->update(['status' => 'suspended']);
            $suspended = $this->dispatch($rawKey);
            $this->expect('suspended → 403', $suspended['status'], 403);
            $this->expect('suspended → tenant_not_active', $suspended['json']['error']['code'] ?? null, 'tenant_not_active');
            $this->expect('suspended blocks READS too (GET is not waved through)', $suspended['status'] !== 200, true);

            // The status the brief's blocklist omits — a lapsed paying customer.
            $tenantA->update(['status' => 'expired_subscription']);
            $lapsed = $this->dispatch($rawKey);
            $this->expect('expired_subscription → 403 (not in the brief blocklist)', $lapsed['status'], 403);

            $tenantA->update(['status' => 'active']);
            $this->expect('reactivated → 200', $this->dispatch($rawKey)['status'], 200);

            // ── 10. Scope enforcement ────────────────────────────────────────
            $this->section('10 · Permission scopes are enforced server-side');

            $this->expect('wildcard satisfies anything', ApiScopes::satisfies(['*'], 'voucher:create'), true);
            $this->expect('exact scope satisfies', ApiScopes::satisfies(['voucher:create'], 'voucher:create'), true);
            $this->expect('read-only key cannot create', ApiScopes::satisfies(['voucher:read'], 'voucher:create'), false);
            $this->expect('empty grant satisfies nothing', ApiScopes::satisfies([], 'voucher:read'), false);
            $this->expect('unknown REQUIRED scope is unsatisfiable (a typo closes, never opens)',
                ApiScopes::satisfies(['*'], 'voucher:invented'), false);
            $this->expect('unknown scope cannot be granted', ApiScopes::sanitize(['voucher:read', 'made:up']), ['voucher:read']);

            // 16A ships no scoped endpoint (ping needs none), so drive the middleware directly —
            // the same object the router would invoke, with a real key and a real Route attached.
            $readOnly = $tenantA->run(fn () => ApiKey::find($scoped['key_row']->id));

            $denied = $this->runScopeMiddleware($readOnly, 'voucher:create');
            $this->expect('read-only key on a voucher:create route → 403', $denied->getStatusCode(), 403);
            $this->expect('… → insufficient_scope', json_decode($denied->getContent(), true)['error']['code'] ?? null, 'insufficient_scope');
            $this->expect('… → names the required scope',
                json_decode($denied->getContent(), true)['error']['details']['required'] ?? null, 'voucher:create');

            $allowed = $this->runScopeMiddleware($readOnly, 'voucher:read');
            $this->expect('read-only key on a voucher:read route → passes', $allowed->getStatusCode(), 200);

            $undeclared = $this->runScopeMiddleware($readOnly, null);
            $this->expect('route declaring NO scope is REFUSED (fail-closed)', $undeclared->getStatusCode(), 403);
            $this->expect('… → the refusal names it as undeclared',
                json_decode($undeclared->getContent(), true)['error']['details']['required'] ?? null, 'undeclared');

            // THE GUARANTEE ITSELF, not just the middleware's behaviour. "Fail-closed by omission"
            // only holds because EnforceApiPermissions is attached to the GROUP: it cannot be
            // skipped, only under-declared. An earlier revision attached it per-route, where
            // forgetting the ->middleware(...) call left a route with NO check at all — the claim
            // held for the mistake nobody makes and not for the likely one. Assert the structure
            // that makes it true, so a future refactor back to per-route fails here.
            $apiRoutes = collect(app('router')->getRoutes()->getRoutes())
                ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));

            $this->expect('every /api/v1/* route enforces scopes (group-attached, unskippable)',
                $apiRoutes->every(fn ($r) => collect($r->gatherMiddleware())
                    ->contains(fn ($m) => is_string($m) && str_contains($m, 'EnforceApiPermissions'))), true);

            $this->expect('every /api/v1/* route DECLARES a scope (none inherits access by omission)',
                $apiRoutes->every(fn ($r) => is_string($r->defaults[EnforceApiPermissions::SCOPE_DEFAULT] ?? null)), true);

            // ── 11. Rate limiting ────────────────────────────────────────────
            $this->section('11 · Rate limiting (60/min per key)');

            $burstKey = $keys->generate($tenantA, null, 'Burst', ['voucher:read'], []);
            RateLimiter::clear('api:apia:'.$burstKey['key_row']->id);   // prior runs share the cache

            $statuses = [];
            for ($i = 1; $i <= 61; $i++) {
                $statuses[] = $this->dispatch($burstKey['key'])['status'];
            }

            $this->expect('first 60 requests all pass', array_unique(array_slice($statuses, 0, 60)), [200]);
            $this->expect('the 61st is rejected', $statuses[60], 429);

            $limited = $this->dispatch($burstKey['key']);
            $this->expect('429 → rate_limited', $limited['json']['error']['code'] ?? null, 'rate_limited');
            $this->expect('429 → Retry-After header present', (bool) $limited['headers']->get('Retry-After'), true);
            $this->expect('429 → retry_after_seconds in the envelope',
                is_int($limited['json']['error']['details']['retry_after_seconds'] ?? null), true);
            $this->expect('429 → X-RateLimit-Remaining is 0', $limited['headers']->get('X-RateLimit-Remaining'), '0');

            // Clearing the limiter models the window expiring.
            RateLimiter::clear('api:apia:'.$burstKey['key_row']->id);
            $this->expect('limit resets → 200', $this->dispatch($burstKey['key'])['status'], 200);

            // The bug the brief's `api:{api_key_id}` key would have caused.
            $this->expect('limiter key is namespaced by tenant (no cross-tenant collision)',
                str_starts_with('api:apia:'.$burstKey['key_row']->id, 'api:apia:'), true);

            // ── 12. The raw key must be nowhere ──────────────────────────────
            $this->section('12 · The raw key is never persisted or logged');

            // POSITIVE CONTROL first. Every check below is a "not found" assertion, and a broken
            // detector — wrong path, empty file, regex that never matches — would report clean
            // while a key leaked in plain sight. Prove the detector can catch a planted secret
            // BEFORE trusting it to say there isn't one.
            $secretPattern = '/zb_live_[A-Za-z0-9]{32}/';
            $this->expect('CONTROL: the leak detector matches a planted key',
                (bool) preg_match($secretPattern, 'noise '.$rawKey.' noise'), true);

            $logPath = storage_path('logs/laravel.log');
            $this->expect('CONTROL: the app log exists and is non-empty (so the scan is real)',
                is_file($logPath) && filesize($logPath) > 0, true);

            $logBody = is_file($logPath) ? (string) file_get_contents($logPath) : '';
            $this->expect('raw key absent from the app log', str_contains($logBody, $rawKey), false);
            $this->expect('no zb_live_ secret in the app log at all', (bool) preg_match($secretPattern, $logBody), false);

            // The rate limiter rides the file cache store, which writes to disk. Its key is built
            // from the tenant + api_key id precisely so no secret is ever persisted there.
            $cacheHits = 0;
            $cacheDir = storage_path('framework/cache/data');

            if (is_dir($cacheDir)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS));

                foreach ($files as $file) {
                    if ($file->isFile() && preg_match($secretPattern, (string) @file_get_contents($file->getPathname()))) {
                        $cacheHits++;
                    }
                }
            }
            $this->expect('no zb_live_ secret written to the file cache (rate limiter keys)', $cacheHits, 0);

            // A customer who puts their key in the URL. It never authenticates (bearer only), but
            // without redaction the query string would persist their live secret in plaintext —
            // and the "no raw key in the log" assertion below would still have passed, because
            // nothing here had ever tried it. Send it, THEN assert.
            $inUrl = $this->dispatch($rawKey, uri: '/api/v1/ping?api_key='.$rawKey.'&page=2');
            $this->expect('a key in the query string still authenticates only via the header', $inUrl['status'], 200);

            $urlRow = $tenantA->run(fn () => ApiRequestLog::where('request_id', $inUrl['request_id'])->first());
            $this->expect('… and the logged query string REDACTS it', str_contains((string) $urlRow?->query_string, $rawKey), false);
            $this->expect('… while keeping the rest of the query intact', str_contains((string) $urlRow?->query_string, 'page=2'), true);

            $logDump = $tenantA->run(fn () => json_encode(ApiRequestLog::all()->toArray()));
            $this->expect('raw key absent from api_request_log', str_contains($logDump, $rawKey), false);
            $this->expect('no zb_live_ secret anywhere in api_request_log',
                (bool) preg_match('/zb_live_[A-Za-z0-9]{32}/', $logDump), false);
            $this->expect('api_request_log stores no request body column',
                $tenantA->run(fn () => \Illuminate\Support\Facades\Schema::hasColumn('api_request_log', 'request_body')), false);

            $keysDump = $tenantA->run(fn () => json_encode(ApiKey::all()->toArray()));
            $this->expect('key_hash is hidden from model serialization', str_contains($keysDump, '$2y$'), false);

            // ── 13. Request log ──────────────────────────────────────────────
            $this->section('13 · Request log');

            $probe = $this->dispatch($rawKey);
            $row = $tenantA->run(fn () => ApiRequestLog::where('request_id', $probe['request_id'])->first());

            $this->expect('a row exists for the request id', $row !== null, true);
            $this->expect('method recorded', $row?->method, 'GET');
            $this->expect('path recorded', $row?->path, '/api/v1/ping');
            $this->expect('status recorded', $row?->response_status, 200);
            $this->expect('duration_ms recorded', is_int($row?->duration_ms), true);
            $this->expect('X-Request-Id matches the logged row', $row?->request_id, $probe['request_id']);
            $this->expect('GET body hash is null (no body)', $row?->request_body_hash, null);
            $this->expect('last_used_at stamped', $tenantA->run(fn () => ApiKey::find($keyRow->id)->last_used_at !== null), true);

            // ── 14. Wiring ───────────────────────────────────────────────────
            $this->section('14 · Route + middleware wiring');

            $route = app('router')->getRoutes()->getByName('api.v1.ping');
            $this->expect('GET /api/v1/ping is registered', $route?->uri(), 'api/v1/ping');

            $sorted = $this->sortedMiddleware($route);
            $this->expect('LogApiRequest runs FIRST (so 401s get an X-Request-Id)',
                $sorted[0] ?? null, LogApiRequest::class);
            $this->expect('IdentifyTenantByApiKey runs before SubstituteBindings',
                array_search(IdentifyTenantByApiKey::class, $sorted, true)
                    < array_search(\Illuminate\Routing\Middleware\SubstituteBindings::class, $sorted, true), true);
            $this->expect('ping declares its scope explicitly as none (never by omission)',
                $route?->defaults[EnforceApiPermissions::SCOPE_DEFAULT] ?? null, 'none');
            $this->expect('scope enforcement is GROUP-attached on ping (cannot be skipped)',
                collect($route?->gatherMiddleware() ?? [])->contains(EnforceApiPermissions::class), true);

            $this->expect('the docs stub is routed', (bool) app('router')->getRoutes()->getByName('docs.api'), true);
            $this->expect('the API keys screen is routed', (bool) app('router')->getRoutes()->getByName('account.api-keys'), true);

            // ── 14b. Error envelope on the paths that never reach the middleware ──
            $this->section('14b · Error envelope outside the middleware chain');

            // LogApiRequest is ROUTE middleware, so nothing below ever reaches it: a 404 has no
            // route, a 405 matched none, and maintenance mode aborts in GLOBAL middleware before
            // routing. These are exactly the responses a customer quotes in a ticket, and they
            // shipped with no X-Request-Id until ApiError learned to mint one.
            $notFound = $this->dispatch($rawKey, uri: '/api/v1/does-not-exist');
            $this->expect('unknown /api/v1 path → 404 not_found', $notFound['json']['error']['code'] ?? null, 'not_found');
            $this->expect('… still carries X-Request-Id (no route middleware ran)', (bool) $notFound['request_id'], true);

            $wrongMethod = $this->dispatch($rawKey, uri: '/api/v1/ping', method: 'DELETE');
            $this->expect('wrong method → 405 method_not_allowed', $wrongMethod['json']['error']['code'] ?? null, 'method_not_allowed');
            $this->expect('… still carries X-Request-Id', (bool) $wrongMethod['request_id'], true);
            $this->expect('… and preserves the Allow header (status alone is not the contract)',
                $wrongMethod['headers']->get('Allow'), 'GET, HEAD');

            // MAINTENANCE MODE — deploy.sh runs `artisan down --retry=15` on EVERY deploy. This
            // must stay a 503 with Retry-After, not become a bare 500: an integration told "we
            // have a bug" pages its on-call and retries hard, where "back in 15s" backs off.
            $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            $downReq = Request::create('/api/v1/ping', 'GET');
            $down = $handler->render($downReq, new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(15));
            $downBody = json_decode($down->getContent(), true);

            $this->expect('maintenance mode stays 503 (not rewritten to 500)', $down->getStatusCode(), 503);
            $this->expect('… → service_unavailable', $downBody['error']['code'] ?? null, 'service_unavailable');
            $this->expect('… → preserves Retry-After', $down->headers->get('Retry-After'), '15');

            // A genuine 5xx: generic body, nothing internal, and an error_id that REALLY is logged
            // (an id the client can quote but support cannot find is worse than no id).
            $logSizeBefore = is_file(storage_path('logs/laravel.log')) ? filesize(storage_path('logs/laravel.log')) : 0;
            $boom = $handler->render(
                Request::create('/api/v1/ping', 'GET'),
                new \Symfony\Component\HttpKernel\Exception\HttpException(502, 'upstream exploded: db=zerobook_central'),
            );
            $boomBody = json_decode($boom->getContent(), true);
            $errorId = $boomBody['error']['details']['error_id'] ?? null;

            clearstatcache();
            $newLog = is_file(storage_path('logs/laravel.log'))
                ? (string) file_get_contents(storage_path('logs/laravel.log'), false, null, $logSizeBefore)
                : '';

            $this->expect('a 5xx abort → generic 500 internal_error', $boomBody['error']['code'] ?? null, 'internal_error');
            $this->expect('… leaks no internal detail to the client', str_contains($boom->getContent(), 'zerobook_central'), false);
            $this->expect('… hands out an error_id', is_string($errorId), true);
            $this->expect('… and that error_id IS in the app log (not a dead reference)',
                is_string($errorId) && str_contains($newLog, $errorId), true);

            // The older /api/* surfaces keep their own {"message": …} contract.
            $sync = $handler->render(Request::create('/api/sync/pull', 'GET'),
                new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(15));
            $this->expect('the sync surface is NOT reshaped by the v1 renderer',
                str_contains((string) $sync->getContent(), 'service_unavailable'), false);

            // ── 15. UI gate ──────────────────────────────────────────────────
            $this->section('15 · API Keys screen is owner-only');

            $this->expect("the privileged role is 'owner' (there is no 'admin' role)", ApiKeysList::ADMIN_ROLE, 'owner');

            foreach (['member' => true, 'accountant' => true, 'owner' => false] as $role => $shouldBlock) {
                $blocked = $this->uiBlocksRole($tenantA, $role);
                $this->expect("role '{$role}' is ".($shouldBlock ? 'blocked' : 'allowed'), $blocked, $shouldBlock);
            }
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            tenancy()->end();
            ActiveCompany::set(null);

            if ($this->option('keep')) {
                $this->info('Tenants apia + apib KEPT (--keep). Their databases remain provisioned.');
            } else {
                foreach ($this->slugs as $slug) {
                    $provisioner->teardown($slug);
                }
                // api_key_directory rows cascade with the tenants row; sweep any orphan anyway so
                // a crashed mid-run leaves nothing behind.
                ApiKeyDirectory::whereIn('tenant_id', $this->slugs)->delete();
                $this->info('Tenants apia + apib torn down (databases dropped).');
            }
        }

        $this->line('');
        $this->info($this->ok
            ? 'ALL API FOUNDATION ASSERTIONS PASSED — keys hash, tenants isolate, scopes enforce, limits hold.'
            : 'API FOUNDATION ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Drive one REAL request through the HTTP kernel: router, sorted middleware, controller,
     * response, terminate(). This is the mechanism that makes the whole proof meaningful — see
     * the class docblock.
     */
    private function dispatch(?string $bearer, array $headers = [], string $uri = '/api/v1/ping', string $method = 'GET'): array
    {
        $server = [];

        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, $method, [], [], [], $server);

        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);   // fires LogApiRequest::terminate()

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
            'json' => json_decode($response->getContent(), true),
            'headers' => $response->headers,
            'request_id' => $response->headers->get('X-Request-Id'),
        ];
    }

    /**
     * Invoke EnforceApiPermissions exactly as the router would: a real Route carrying the scope
     * as a route default (or none at all, when $required is null), bound to a real Request with
     * an authenticated key attached.
     */
    private function runScopeMiddleware(ApiKey $key, ?string $required): \Symfony\Component\HttpFoundation\Response
    {
        $route = new \Illuminate\Routing\Route('POST', '/api/v1/probe', ['uses' => fn () => 'ok']);

        if ($required !== null) {
            $route->defaults(EnforceApiPermissions::SCOPE_DEFAULT, $required);
        }

        $request = Request::create('/api/v1/probe', 'POST');
        $request->setRouteResolver(fn () => $route);
        $request->attributes->set(ApiError::API_KEY_ATTR, $key);
        $request->attributes->set(ApiError::REQUEST_ID_ATTR, 'PROOF');

        return (new EnforceApiPermissions())->handle($request, fn () => new \Illuminate\Http\Response('ok', 200));
    }

    /** Does the API Keys screen block this role? Mounts the real component behind the real guard. */
    private function uiBlocksRole(Tenant $tenant, string $role): bool
    {
        $user = new \App\Models\TenantUser(['tenant_id' => $tenant->getTenantKey(), 'email' => "{$role}@proof.test", 'role' => $role]);
        $user->id = 999;

        \Illuminate\Support\Facades\Auth::guard('tenant')->setUser($user);

        try {
            return $tenant->run(function () {
                try {
                    (new ApiKeysList())->mount();

                    return false;   // reached the screen
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    return $e->getStatusCode() === 403;
                }
            });
        } finally {
            \Illuminate\Support\Facades\Auth::guard('tenant')->logout();
        }
    }

    /** The order middleware ACTUALLY executes in, after the priority sorter has had its say. */
    private function sortedMiddleware(\Illuminate\Routing\Route $route): array
    {
        $router = app('router');
        $method = new \ReflectionMethod($router, 'gatherRouteMiddleware');
        $method->setAccessible(true);

        return array_values($method->invoke($router, $route));
    }

    /** Wall-clock milliseconds for one call. */
    private function timeOf(callable $work): float
    {
        $start = microtime(true);
        $work();

        return (microtime(true) - $start) * 1000;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ".str_repeat('─', max(1, 62 - mb_strlen($title))));
    }

    private function expect(string $label, mixed $actual, mixed $expected): void
    {
        $pass = $actual === $expected;

        if (! $pass) {
            $this->ok = false;
        }

        $this->line(sprintf('   [%s] %s = %s%s',
            $pass ? 'PASS' : 'FAIL', $label, json_encode($actual),
            $pass ? '' : ' (expected '.json_encode($expected).')'));
    }
}
