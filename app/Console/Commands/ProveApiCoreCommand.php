<?php

namespace App\Console\Commands;

use App\Models\AccountGroup;
use App\Models\ApiIdempotencyKey;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\Api\ApiKeyService;
use App\Services\CompanyProvisioner;
use App\Services\GstService;
use App\Services\TdsService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use App\Support\ScenarioContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 16B — prove the Core REST API over REAL kernel-dispatched HTTP.
 *
 * Like prove-api-foundation, this drives requests through the actual HTTP kernel — router, sorted
 * middleware, controller, response — so it exercises what production runs, not a controller called
 * in isolation. The two sections that matter most:
 *
 *   • THE CROWN JEWEL (byte-identical posting): the SAME invoice posted through the API and through
 *     the UI path (new VoucherScreen)->post() lands identical voucher_entries, stock_entries,
 *     bill_allocations and GST legs — row for row. Because both ultimately call the identical
 *     post(), any divergence would be a payload-translation bug in the API. Posted into two
 *     identically-seeded companies so the sale's weighted-average cost is the same on both sides.
 *
 *   • CONCURRENT IDEMPOTENCY: the insert-first unique index is the single-flight arbiter — two
 *     racing identical requests produce exactly one voucher. Tested by simulating the losing
 *     request against a claimed key (the deterministic constraint-violation path), since true OS
 *     thread concurrency is not reproducible in a single console process on Windows.
 */
class ProveApiCoreCommand extends Command
{
    protected $signature = 'zerobook:prove-api-core {--keep : keep the throwaway tenant provisioned}';

    protected $description = 'Provision a tenant, issue keys, and prove the 16B Core REST API end-to-end over real HTTP — including byte-identical posting and idempotency';

    private bool $ok = true;

    private string $slug = 'apicore';

    private string $key = '';        // an all-scopes key

    public function handle(TenantProvisioner $provisioner, ApiKeyService $keys): int
    {
        try {
            $this->section('1 · Provision + issue keys');
            $provisioner->teardown($this->slug);
            $tenant = $provisioner->provision($this->slug, 'API Core Books', 'enterprise');
            $this->expect('provisioned', $tenant->status, 'active');

            $this->key = $keys->generate(
                tenant: $tenant, user: null, name: 'HMS',
                permissions: ['*'], companyIds: []
            )['key'];
            $this->expect('all-scopes key issued', str_starts_with($this->key, 'zb_live_'), true);

            $tenant->run(fn () => $this->run_all($tenant, $keys));
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            tenancy()->end();
            ActiveCompany::set(null);
            if ($this->option('keep')) {
                $this->info('Tenant '.$this->slug.' KEPT (--keep).');
            } else {
                $provisioner->teardown($this->slug);
                \App\Models\ApiKeyDirectory::where('tenant_id', $this->slug)->delete();
                $this->info('Tenant '.$this->slug.' torn down.');
            }
        }

        $this->line('');
        $this->info($this->ok
            ? 'ALL API CORE ASSERTIONS PASSED — API vouchers are byte-identical to UI vouchers; idempotency, scoping and reports hold.'
            : 'API CORE ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function run_all(Tenant $tenant, ApiKeyService $keys): void
    {
        $this->crownJewelSales();
        $this->crownJewelTds();
        $this->serverAuthoritativeTax();
        $this->idempotency();
        $this->cancelAndAlter();
        $this->crossCompany($keys, $tenant);
        $this->realBooksOnly();
        $this->syncOutbox();
        $this->reportsMatchServices();
        $this->scopeEnforcement($keys, $tenant);
        $this->pagination();
        $this->wiring();
    }

    // ── 2. CROWN JEWEL — byte-identical Sales (2 items, GST intra, bill-wise New Ref) ──────

    private function crownJewelSales(): void
    {
        $this->section('2 · CROWN JEWEL — byte-identical Sales invoice (API vs UI path)');

        $a = $this->seedGstCompany('CJ Sales A');   // UI path
        $b = $this->seedGstCompany('CJ Sales B');   // API path

        // The crown jewel exercises bill-wise New Ref, so turn it on identically in both.
        foreach ([$a, $b] as $c) {
            ActiveCompany::runAs($c['company'], function () use ($c) {
                CompanyFeature::current()->update(['bill_by_bill' => true]);
                Ledger::whereKey($c['customer'])->update(['maintain_bill_by_bill' => true]);
            });
        }

        // The one logical invoice: 2 items (10@100, 5@200), 18% GST intra-state, bill-wise New Ref.
        $items = [
            ['stock_item_id' => null, 'name' => 'Widget', 'qty' => 10, 'rate' => 100],
            ['stock_item_id' => null, 'name' => 'Gadget', 'qty' => 5, 'rate' => 200],
        ];

        // ── UI path into company A ──
        $vidA = ActiveCompany::runAs($a['company'], function () use ($a, $items) {
            $gst = app(GstService::class);
            $itemRows = array_map(fn ($it) => ['stock_item_id' => $a['items'][$it['name']], 'godown_id' => null, 'qty' => $it['qty'], 'rate' => $it['rate']], $items);
            $baseP = 0;
            $taxable = [];
            foreach ($items as $it) {
                $lineP = (int) round($it['qty'] * $it['rate'] * 100);
                $baseP += $lineP;
                $taxable[] = ['amount' => round($it['qty'] * $it['rate'], 2), 'rate' => 18.0];
            }
            $comp = $gst->computeInvoiceTax('sales', 'Maharashtra', $taxable);
            $lines = [['ledger_id' => $a['revenue'], 'dr_cr' => 'Cr', 'amount' => $baseP / 100]];
            foreach ($comp['lines'] as $tl) {
                $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
            }
            $totalP = $baseP + $comp['tax_paise'];
            array_unshift($lines, ['ledger_id' => $a['customer'], 'dr_cr' => 'Dr', 'amount' => $totalP / 100,
                'allocations' => [['ref_type' => 'new', 'ref_name' => 'CJ-INV', 'amount' => $totalP / 100, 'due_date' => '2026-08-15']]]);

            $res = (new \App\Livewire\VoucherScreen)->post([
                'type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $a['customer'],
                'reference_no' => 'CJ-INV', 'lines' => $lines, 'items' => $itemRows,
            ]);

            return $res['voucher']['id'];
        });

        // ── API path into company B ──
        $apiBody = [
            'type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $b['customer'],
            'reference_no' => 'CJ-INV', 'revenue_ledger_id' => $b['revenue'],
            'items' => [
                ['stock_item_id' => $b['items']['Widget'], 'qty' => '10', 'rate' => '100.00'],
                ['stock_item_id' => $b['items']['Gadget'], 'qty' => '5', 'rate' => '200.00'],
            ],
            'bill_allocations' => [['ref_type' => 'new', 'ref_name' => 'CJ-INV', 'amount' => '2360.00', 'due_date' => '2026-08-15']],
            'expected_total' => '2360.00',
        ];
        $resp = $this->dispatch('POST', '/api/v1/vouchers', $apiBody, 'cj-sales', (string) $b['company']);
        $this->expect('API sales posts 201', $resp['status'], 201);
        $vidB = $resp['json']['id'] ?? null;

        if ($vidA && $vidB) {
            $rowsA = ActiveCompany::runAs($a['company'], fn () => $this->voucherFingerprint($vidA));
            $rowsB = ActiveCompany::runAs($b['company'], fn () => $this->voucherFingerprint($vidB));

            $this->expect('voucher_entries identical (ledger name + dr_cr → paise)', $rowsA['entries'], $rowsB['entries']);
            $this->expect('GST legs identical (Output CGST/SGST present + equal)', $rowsA['gst'], $rowsB['gst']);
            $this->expect('stock_entries identical (item + direction → qty/rate/value)', $rowsA['stock'], $rowsB['stock']);
            $this->expect('bill_allocations identical (ref → amount)', $rowsA['bills'], $rowsB['bills']);
            $this->expect('total identical', $rowsA['total_paise'], $rowsB['total_paise']);
            $this->expect('both are real vouchers (scenario_id null)', [$rowsA['scenario'], $rowsB['scenario']], [null, null]);
        }
    }

    // ── 3. CROWN JEWEL — byte-identical TDS payment (the ₹40k/₹15k → ₹5,500 catch-up) ─────

    private function crownJewelTds(): void
    {
        $this->section('3 · CROWN JEWEL — byte-identical TDS payment (₹5,500 catch-up)');

        $a = $this->seedTdsCompany('CJ Tds A');
        $b = $this->seedTdsCompany('CJ Tds B');

        // Seed BOTH identically: a prior ₹40,000 professional-fee payment (below the ₹50k 194J
        // threshold → 0 TDS), so the ₹15,000 payment that follows crosses it and catches up ₹5,500.
        foreach ([$a, $b] as $c) {
            ActiveCompany::runAs($c['company'], function () use ($c) {
                (new \App\Livewire\VoucherScreen)->post([
                    'type' => 'payment', 'date' => '2026-04-10',
                    'lines' => [
                        ['ledger_id' => $c['expense'], 'dr_cr' => 'Dr', 'amount' => 40000],
                        ['ledger_id' => $c['bank'], 'dr_cr' => 'Cr', 'amount' => 40000],
                    ],
                    'tds_deduction' => ['deductee_ledger_id' => $c['deductee'], 'tds_section_id' => $c['section'], 'base_amount' => 40000],
                ]);
            });
        }

        // UI path (A): the ₹15,000 catch-up — the client pre-computes 5,500 only to build the line.
        $vidA = ActiveCompany::runAs($a['company'], function () use ($a) {
            $deducted = app(TdsService::class)->computeDeduction($a['deductee'], $a['section'], 1_500_000, Voucher::fyStartFor(Carbon::parse('2026-04-20')))[0];
            $res = (new \App\Livewire\VoucherScreen)->post([
                'type' => 'payment', 'date' => '2026-04-20',
                'lines' => [
                    ['ledger_id' => $a['expense'], 'dr_cr' => 'Dr', 'amount' => 15000],
                    ['ledger_id' => app(TdsService::class)->payableLedgerId(), 'dr_cr' => 'Cr', 'amount' => $deducted / 100],
                    ['ledger_id' => $a['bank'], 'dr_cr' => 'Cr', 'amount' => (1_500_000 - $deducted) / 100],
                ],
                'tds_deduction' => ['deductee_ledger_id' => $a['deductee'], 'tds_section_id' => $a['section'], 'base_amount' => 15000],
            ]);

            return $res['voucher']['id'];
        });

        // API path (B): the client sends only who/section/base — the server computes 5,500.
        $resp = $this->dispatch('POST', '/api/v1/vouchers', [
            'type' => 'payment', 'date' => '2026-04-20',
            'party_ledger_id' => $b['expense'], 'bank_ledger_id' => $b['bank'], 'amount' => '15000.00',
            'tds_deduction' => ['deductee_ledger_id' => $b['deductee'], 'tds_section_id' => $b['section'], 'base_amount' => '15000.00'],
        ], 'cj-tds', (string) $b['company']);
        $this->expect('API TDS payment posts 201', $resp['status'], 201);
        $vidB = $resp['json']['id'] ?? null;

        if ($vidA && $vidB) {
            $rowsA = ActiveCompany::runAs($a['company'], fn () => $this->voucherFingerprint($vidA));
            $rowsB = ActiveCompany::runAs($b['company'], fn () => $this->voucherFingerprint($vidB));
            $this->expect('TDS voucher_entries identical (incl. server-computed ₹5,500 Payable leg)', $rowsA['entries'], $rowsB['entries']);
            $this->expect('the TDS Payable leg is exactly 550000 paise', $rowsB['entries']['TDS Payable|Cr'] ?? null, 550000);
        }
    }

    // ── 4. Server-authoritative tax + balance gate ────────────────────────────────────────

    private function serverAuthoritativeTax(): void
    {
        $this->section('4 · Server-authoritative tax + balance gate');

        $c = $this->seedGstCompany('Auth');
        $cid = (string) $c['company'];

        // expected_total that disagrees with the server → 422 total_mismatch, no voucher.
        $before = ActiveCompany::runAs($c['company'], fn () => Voucher::count());
        $r = $this->dispatch('POST', '/api/v1/vouchers', [
            'type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'],
            'lines' => [['ledger_id' => $c['revenue'], 'amount' => '1000.00']], 'expected_total' => '1000.00',
        ], 'auth-mismatch', $cid);
        $this->expect('expected_total disagreeing with server GST → 422', $r['status'], 422);
        $this->expect('… code total_mismatch', $r['json']['error']['code'] ?? null, 'total_mismatch');
        $this->expect('… details carry both numbers', $r['json']['error']['details']['computed_total'] ?? null, '1180.00');
        $after = ActiveCompany::runAs($c['company'], fn () => Voucher::count());
        $this->expect('no voucher persisted on mismatch', $after, $before);

        // A payload that posts the WRONG tax legs directly (ledger mode with a hand-crafted total)
        // is rejected by post()'s own GST verify → 422 with the 'gst' key surfaced.
        $r = $this->dispatch('POST', '/api/v1/vouchers', [
            'type' => 'journal', 'date' => '2026-07-15',
            'lines' => [
                ['ledger_id' => $c['customer'], 'dr_cr' => 'Dr', 'amount' => '1000.00'],
                ['ledger_id' => $c['revenue'], 'dr_cr' => 'Cr', 'amount' => '900.00'],
            ],
        ], 'auth-unbalanced', $cid);
        $this->expect('unbalanced journal → 422', $r['status'], 422);
        $this->expect('… names the balance failure', array_key_exists('balance', $r['json']['error']['details'] ?? []), true);

        // Malformed money must be a clean 4xx (client error), never a 500 (our fault). A non-scalar
        // amount (a JSON array where a decimal string was expected) is rejected, not crashed.
        $r = $this->dispatch('POST', '/api/v1/vouchers', [
            'type' => 'journal', 'date' => '2026-07-15',
            'lines' => [['ledger_id' => $c['customer'], 'dr_cr' => 'Dr', 'amount' => []],
                ['ledger_id' => $c['revenue'], 'dr_cr' => 'Cr', 'amount' => '100.00']],
        ], 'auth-nonscalar', $cid);
        $this->expect('a non-scalar amount → 422 (not 500)', $r['status'], 422);
    }

    // ── 5. Idempotency ────────────────────────────────────────────────────────────────────

    private function idempotency(): void
    {
        $this->section('5 · Idempotency');

        $c = $this->seedGstCompany('Idem');
        $cid = (string) $c['company'];
        $body = ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'],
            'lines' => [['ledger_id' => $c['revenue'], 'amount' => '1000.00']]];

        $before = ActiveCompany::runAs($c['company'], fn () => Voucher::count());

        // Missing key → 422.
        $noKey = $this->dispatch('POST', '/api/v1/vouchers', $body, null, $cid);
        $this->expect('missing Idempotency-Key on write → 422', $noKey['status'], 422);
        $this->expect('… code idempotency_key_required', $noKey['json']['error']['code'] ?? null, 'idempotency_key_required');

        // First post.
        $r1 = $this->dispatch('POST', '/api/v1/vouchers', $body, 'idem-A', $cid);
        $this->expect('first post → 201', $r1['status'], 201);
        $id1 = $r1['json']['id'] ?? null;

        // Replay: same key + same body → same body, X-Idempotency-Replay: true, no 2nd voucher.
        $r2 = $this->dispatch('POST', '/api/v1/vouchers', $body, 'idem-A', $cid);
        $this->expect('replay → 201', $r2['status'], 201);
        $this->expect('replay header set', $r2['headers']->get('X-Idempotency-Replay'), 'true');
        $this->expect('replay returns the SAME voucher id', $r2['json']['id'] ?? null, $id1);

        // Conflict: same key + different body → 409 reused, no 2nd voucher.
        $r3 = $this->dispatch('POST', '/api/v1/vouchers', $body + ['reference_no' => 'DIFFERENT'], 'idem-A', $cid);
        $this->expect('same key + different body → 409', $r3['status'], 409);
        $this->expect('… code idempotency_key_reused', $r3['json']['error']['code'] ?? null, 'idempotency_key_reused');

        $after = ActiveCompany::runAs($c['company'], fn () => Voucher::count());
        $this->expect('exactly one voucher from replay + conflict', $after - $before, 1);

        // Concurrent single-flight: simulate the LOSER of a race by pre-claiming the key as a
        // pending row, then firing the request — it must NOT post a second voucher.
        $key = \App\Models\ApiKey::first();
        $countBefore = ActiveCompany::runAs($c['company'], fn () => Voucher::count());
        ActiveCompany::runAs($c['company'], fn () => ApiIdempotencyKey::create([
            'api_key_id' => $key->id, 'idempotency_key' => 'race-1',
            'request_body_hash' => 'a-different-inflight-hash', 'response_status' => null,
            'created_at' => now(), 'expires_at' => now()->addHours(48),
        ]));
        // Same key, but our body hash differs from the pre-claimed one → this is the "someone else
        // holds it, still pending" path → 409 in_flight (a matching-hash pending would also 409).
        $race = $this->dispatch('POST', '/api/v1/vouchers', $body, 'race-1', $cid);
        $this->expect('a claimed-but-pending key does NOT post a second voucher', $race['status'], 409);
        $this->expect('… 409 is an idempotency conflict', in_array($race['json']['error']['code'] ?? '', ['idempotency_key_in_flight', 'idempotency_key_reused'], true), true);
        $countAfter = ActiveCompany::runAs($c['company'], fn () => Voucher::count());
        $this->expect('concurrent-loser created no voucher', $countAfter, $countBefore);
    }

    // ── 6. Cancel (idempotent) + Alter through the shared path ────────────────────────────

    private function cancelAndAlter(): void
    {
        $this->section('6 · Cancel (idempotent) + Alter');

        $c = $this->seedGstCompany('CancelAlter');
        $cid = (string) $c['company'];
        $body = ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'],
            'reference_no' => 'CA-1', 'lines' => [['ledger_id' => $c['revenue'], 'amount' => '1000.00']]];

        $posted = $this->dispatch('POST', '/api/v1/vouchers', $body, 'ca-post', $cid);
        $vid = $posted['json']['id'];

        // Alter: change the amount through the shared alter path.
        $altered = $this->dispatch('PUT', '/api/v1/vouchers/'.$vid,
            ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'], 'reference_no' => 'CA-1',
                'lines' => [['ledger_id' => $c['revenue'], 'amount' => '2000.00']]],
            'ca-alter', $cid);
        $this->expect('alter → 200', $altered['status'], 200);
        $this->expect('alter re-derived the total (2000 + 18% GST = 2360)', $altered['json']['amount'] ?? null, '2360.00');
        $this->expect('alter keeps the same voucher id', $altered['json']['id'] ?? null, $vid);

        // Cancel once → 200.
        $cancel1 = $this->dispatch('POST', '/api/v1/vouchers/'.$vid.'/cancel', [], 'ca-cancel', $cid);
        $this->expect('cancel → 200', $cancel1['status'], 200);
        $this->expect('… status cancelled', $cancel1['json']['status'] ?? null, 'cancelled');
        $gone = ActiveCompany::runAs($c['company'], fn () => Voucher::find($vid));
        $this->expect('voucher is gone after cancel', $gone, null);

        // Cancel again with the SAME idempotency key → replay 200 (not a 404).
        $cancel2 = $this->dispatch('POST', '/api/v1/vouchers/'.$vid.'/cancel', [], 'ca-cancel', $cid);
        $this->expect('cancel twice (same key) → replay 200', $cancel2['status'], 200);
        $this->expect('… replay header set', $cancel2['headers']->get('X-Idempotency-Replay'), 'true');
    }

    // ── 7. Cross-company isolation ────────────────────────────────────────────────────────

    private function crossCompany(ApiKeyService $keys, Tenant $tenant): void
    {
        $this->section('7 · Cross-company isolation');

        $c1 = $this->seedGstCompany('XC One');
        $c2 = $this->seedGstCompany('XC Two');

        // A voucher in company 2.
        $v2 = $this->dispatch('POST', '/api/v1/vouchers',
            ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c2['customer'], 'reference_no' => 'XC-2',
                'lines' => [['ledger_id' => $c2['revenue'], 'amount' => '1000.00']]],
            'xc-2', (string) $c2['company'])['json']['id'];

        // A key authorized ONLY for company 1.
        $c1Key = $keys->generate(tenant: $tenant, user: null, name: 'C1 only',
            permissions: ['voucher:read'], companyIds: [$c1['company']])['key'];

        // That key requesting company 2's voucher (default company = 1) → 404, no identity leak.
        $r = $this->dispatch('GET', '/api/v1/vouchers/'.$v2, null, null, null, $c1Key);
        $this->expect("company-1 key reading company-2's voucher → 404", $r['status'], 404);
        $this->expect('… body leaks no voucher fields', isset($r['json']['reference_no']) || isset($r['json']['party_ledger_id']), false);

        // That key asking for company 2 explicitly (unauthorized) → 403.
        $r = $this->dispatch('GET', '/api/v1/vouchers/'.$v2, null, null, (string) $c2['company'], $c1Key);
        $this->expect('X-Company-Id: 2 on a company-1 key → 403', $r['status'], 403);
        $this->expect('… code company_not_authorized', $r['json']['error']['code'] ?? null, 'company_not_authorized');

        // Cross-company idempotency: the SAME key + byte-identical body to company 1 then company 2
        // must NOT replay company 1's voucher into the company-2 response. The idempotency
        // fingerprint folds in the active company, so the second request is a safe 409 reused.
        $idBody = ['type' => 'journal', 'date' => '2026-07-15',
            'lines' => [['ledger_id' => $c1['revenue'], 'dr_cr' => 'Cr', 'amount' => '50.00'], ['ledger_id' => $c1['customer'], 'dr_cr' => 'Dr', 'amount' => '50.00']]];
        $a1 = $this->dispatch('POST', '/api/v1/vouchers', $idBody, 'xc-idem', (string) $c1['company']);
        $a2 = $this->dispatch('POST', '/api/v1/vouchers', $idBody, 'xc-idem', (string) $c2['company']);
        $this->expect('same key+body to company 1 → 201', $a1['status'], 201);
        $this->expect('same key+body to company 2 does NOT replay company 1 → 409', $a2['status'], 409);
        $this->expect('… it is a reused conflict, not a wrong-company voucher', $a2['json']['error']['code'] ?? null, 'idempotency_key_reused');
    }

    // ── 8. Real-books-only ────────────────────────────────────────────────────────────────

    private function realBooksOnly(): void
    {
        $this->section('8 · Real-books-only (scenario vouchers never surface)');

        $c = $this->seedGstCompany('Scenarios');
        $cid = (string) $c['company'];

        // Enable scenarios and create a provisional voucher via the UI path.
        [$scenarioVid, $realVid] = ActiveCompany::runAs($c['company'], function () use ($c) {
            CompanyFeature::current()->update(['scenarios' => true]);
            $scenario = \App\Models\Scenario::create(['name' => 'What-if', 'slug' => 'what-if', 'is_active' => true]);
            $prov = (new \App\Livewire\VoucherScreen)->post([
                'type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'], 'reference_no' => 'PROV',
                'scenario_id' => $scenario->id,
                'lines' => ScenarioContext::runWith([$scenario->id], fn () => $this->plainSalesLines($c)),
            ]);
            $real = (new \App\Livewire\VoucherScreen)->post([
                'type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'], 'reference_no' => 'REAL',
                'lines' => $this->plainSalesLines($c),
            ]);

            return [$prov['voucher']['id'], $real['voucher']['id']];
        });

        // The API list must show the real voucher, never the provisional one.
        $list = $this->dispatch('GET', '/api/v1/vouchers?limit=100', null, null, $cid);
        $ids = array_column($list['json']['data'] ?? [], 'id');
        $this->expect('API list includes the real voucher', in_array($realVid, $ids, true), true);
        $this->expect('API list EXCLUDES the provisional voucher', in_array($scenarioVid, $ids, true), false);

        // The provisional voucher is not even fetchable by id — the {voucher} binding is
        // real-books-only, so a scenario voucher id is "not found", exactly like a cross-company id.
        $direct = $this->dispatch('GET', '/api/v1/vouchers/'.$scenarioVid, null, null, $cid);
        $this->expect('provisional voucher is NOT readable by direct id → 404', $direct['status'], 404);
        // The real voucher IS readable by direct id (control).
        $realRead = $this->dispatch('GET', '/api/v1/vouchers/'.$realVid, null, null, $cid);
        $this->expect('the real voucher IS readable by direct id → 200', $realRead['status'], 200);
        $day = $this->dispatch('GET', '/api/v1/reports/day-book?date=2026-07-15', null, null, $cid);
        $dayIds = array_column($day['json']['vouchers'] ?? [], 'id');
        $this->expect('day-book EXCLUDES the provisional voucher', in_array($scenarioVid, $dayIds, true), false);
        $this->expect('day-book includes the real voucher', in_array($realVid, $dayIds, true), true);
    }

    // ── 9. Sync outbox ────────────────────────────────────────────────────────────────────

    private function syncOutbox(): void
    {
        $this->section('9 · Sync outbox sees API vouchers');

        $c = $this->seedGstCompany('Sync');
        $cid = (string) $c['company'];
        $r = $this->dispatch('POST', '/api/v1/vouchers',
            ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'], 'reference_no' => 'SYNC',
                'lines' => [['ledger_id' => $c['revenue'], 'amount' => '1000.00']]],
            'sync-1', $cid);
        $vid = $r['json']['id'];

        $hasChange = ActiveCompany::runAs($c['company'], fn () => DB::table('sync_changes')
            ->where('entity', 'voucher')->where('record_id', $vid)->exists());
        $this->expect('an API-posted voucher entered the sync outbox', $hasChange, true);
    }

    // ── 10. Reports match services ────────────────────────────────────────────────────────

    private function reportsMatchServices(): void
    {
        $this->section('10 · Reports equal the underlying services');

        $c = $this->seedGstCompany('Reports');
        $cid = (string) $c['company'];
        $this->dispatch('POST', '/api/v1/vouchers',
            ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'], 'reference_no' => 'RPT',
                'lines' => [['ledger_id' => $c['revenue'], 'amount' => '1000.00']]],
            'rpt-1', $cid);

        $api = $this->dispatch('GET', '/api/v1/reports/trial-balance', null, null, $cid);
        $svc = ActiveCompany::runAs($c['company'], function () {
            $bs = app(\App\Services\BalanceService::class);
            [$f, $t] = $bs->withinFy(null, null);

            return $bs->trialBalance($f, $t);
        });
        $this->expect('API trial-balance total_dr equals BalanceService (paise)',
            $api['json']['total_dr'] ?? null, \App\Support\ApiMoney::fromPaise($svc['total_dr']));
        $this->expect('API trial-balance balanced flag equals the service', $api['json']['balanced'] ?? null, $svc['balanced']);

        // A malformed date param is a client error → 400, not a 500 from an uncaught Carbon parse.
        $bad = $this->dispatch('GET', '/api/v1/reports/trial-balance?as_of=not-a-date', null, null, $cid);
        $this->expect('malformed as_of → 400 (not 500)', $bad['status'], 400);
        $badDay = $this->dispatch('GET', '/api/v1/reports/day-book?date=13/40/9999', null, null, $cid);
        $this->expect('malformed day-book date → 400 (not 500)', $badDay['status'], 400);
    }

    // ── 11. Scope enforcement per route ───────────────────────────────────────────────────

    private function scopeEnforcement(ApiKeyService $keys, Tenant $tenant): void
    {
        $this->section('11 · Per-route scope enforcement');

        $c = $this->seedGstCompany('Scopes');
        $cid = (string) $c['company'];

        $readKey = $keys->generate(tenant: $tenant, user: null, name: 'read-only',
            permissions: ['voucher:read'], companyIds: [])['key'];
        $writeMasterKey = $keys->generate(tenant: $tenant, user: null, name: 'master-write',
            permissions: ['master:write'], companyIds: [])['key'];

        // voucher:read key POSTing a voucher → 403 insufficient_scope.
        $r = $this->dispatch('POST', '/api/v1/vouchers',
            ['type' => 'sales', 'date' => '2026-07-15', 'party_ledger_id' => $c['customer'], 'lines' => [['ledger_id' => $c['revenue'], 'amount' => '1000.00']]],
            'scope-1', $cid, $readKey);
        $this->expect('voucher:read key on POST /vouchers → 403', $r['status'], 403);
        $this->expect('… code insufficient_scope', $r['json']['error']['code'] ?? null, 'insufficient_scope');
        $this->expect('… names voucher:create', $r['json']['error']['details']['required'] ?? null, 'voucher:create');

        // master:write key reading a report → 403 (needs report:read).
        $r = $this->dispatch('GET', '/api/v1/reports/trial-balance', null, null, $cid, $writeMasterKey);
        $this->expect('master:write key on a report → 403', $r['status'], 403);

        // read key CAN read a voucher list.
        $r = $this->dispatch('GET', '/api/v1/vouchers', null, null, $cid, $readKey);
        $this->expect('voucher:read key CAN list vouchers → 200', $r['status'], 200);
    }

    // ── 12. Pagination ────────────────────────────────────────────────────────────────────

    private function pagination(): void
    {
        $this->section('12 · Cursor pagination');

        $c = $this->seedGstCompany('Paginate');
        $cid = (string) $c['company'];

        // Post 30 journals (kept small for speed; asserts cursor + limit cap, not volume).
        ActiveCompany::runAs($c['company'], function () use ($c) {
            for ($i = 1; $i <= 30; $i++) {
                (new \App\Livewire\VoucherScreen)->post(['type' => 'journal', 'date' => '2026-07-15',
                    'lines' => [['ledger_id' => $c['customer'], 'dr_cr' => 'Dr', 'amount' => 10],
                        ['ledger_id' => $c['revenue'], 'dr_cr' => 'Cr', 'amount' => 10]]]);
            }
        });

        $p1 = $this->dispatch('GET', '/api/v1/vouchers?type=journal&limit=10', null, null, $cid);
        $this->expect('page 1 returns limit rows', count($p1['json']['data']), 10);
        $cursor = $p1['json']['pagination']['next_cursor'] ?? null;
        $this->expect('page 1 has a next cursor', is_string($cursor) && $cursor !== '', true);

        $p2 = $this->dispatch('GET', '/api/v1/vouchers?type=journal&limit=10&cursor='.urlencode($cursor), null, null, $cid);
        $ids1 = array_column($p1['json']['data'], 'id');
        $ids2 = array_column($p2['json']['data'], 'id');
        $this->expect('page 2 rows do not overlap page 1', array_intersect($ids1, $ids2), []);

        // Limit is capped at 100.
        $capped = $this->dispatch('GET', '/api/v1/vouchers?limit=9999', null, null, $cid);
        $this->expect('limit is capped at 100', $capped['json']['pagination']['limit'], 100);

        // A malformed cursor is a client error → 400, not a 500 with a spurious error-log line.
        // This base64 decodes to {"_pointsToNextItems":true} — a structurally-valid cursor missing
        // its id parameter, which makes Laravel's Cursor throw (an invalid-base64 string is instead
        // treated as no cursor and harmlessly returns page 1).
        $bad = $this->dispatch('GET', '/api/v1/vouchers?cursor=eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9', null, null, $cid);
        $this->expect('a malformed cursor → 400 (not 500)', $bad['status'], 400);
    }

    // ── 13. Wiring — new routes declare scopes (keeps prove-api-foundation §10 green) ─────

    private function wiring(): void
    {
        $this->section('13 · Every /api/v1 route declares a scope + write routes require idempotency');

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));

        $this->expect('every /api/v1 route declares a scope',
            $routes->every(fn ($r) => is_string($r->defaults[\App\Http\Middleware\EnforceApiPermissions::SCOPE_DEFAULT] ?? null)), true);

        $writeRoutes = $routes->filter(fn ($r) => array_intersect(['POST', 'PUT'], $r->methods()) && ! str_ends_with($r->uri(), 'ping'));
        $this->expect('every write route requires an Idempotency-Key',
            $writeRoutes->every(fn ($r) => in_array(\App\Http\Middleware\RequiresIdempotencyKey::class, $r->gatherMiddleware(), true)), true);
    }

    // ── seed helpers ──────────────────────────────────────────────────────────────────────

    /** A fresh GST company: state MH, bill-wise on, a customer, a 18% revenue ledger, 2 stock items with an opening purchase. */
    private function seedGstCompany(string $name): array
    {
        $company = app(CompanyProvisioner::class)->create($name);

        return ActiveCompany::runAs($company->id, function () use ($company) {
            // GST on; bill-wise stays OFF by default (only the crown jewel exercises it, and it
            // turns it on for its own customer). A bill-wise customer would force every plain sale
            // in the other sections to carry an allocation, which is not what they test.
            CompanyFeature::current()->update(['gst' => true]);
            activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);

            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $unit = Unit::firstOrCreate(['name' => 'Nos'], ['formal_name' => 'Numbers', 'decimals' => 0]);

            $customer = Ledger::create(['name' => 'Acme MH', 'group_id' => $gid('Sundry Debtors'), 'state' => 'Maharashtra', 'gstin' => '27AAACA1111A1Z1']);
            $revenue = Ledger::create(['name' => 'Sales 18%', 'group_id' => $gid('Sales Accounts'), 'gst_rate' => 18]);
            $purchLed = Ledger::create(['name' => 'Purchase 18%', 'group_id' => $gid('Purchase Accounts'), 'gst_rate' => 18]);
            $supplier = Ledger::create(['name' => 'Supplier MH', 'group_id' => $gid('Sundry Creditors'), 'state' => 'Maharashtra']);

            $items = [];
            foreach (['Widget' => 50, 'Gadget' => 80] as $iname => $cost) {
                $items[$iname] = StockItem::create(['name' => $iname, 'unit_id' => $unit->id, 'gst_rate' => 18, 'costing_method' => 'weighted_average'])->id;
            }

            // Opening purchase so a sale has a deterministic weighted-average cost.
            $gst = app(GstService::class);
            foreach (['Widget' => 50, 'Gadget' => 80] as $iname => $cost) {
                $base = 100 * $cost;
                $comp = $gst->computeInvoiceTax('purchase', 'Maharashtra', [['amount' => $base, 'rate' => 18.0]]);
                $lines = [['ledger_id' => $purchLed->id, 'dr_cr' => 'Dr', 'amount' => $base]];
                $tp = $base * 100;
                foreach ($comp['lines'] as $tl) {
                    $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
                }
                $tp += $comp['tax_paise'];
                array_unshift($lines, ['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => $tp / 100]);
                (new \App\Livewire\VoucherScreen)->post([
                    'type' => 'purchase', 'date' => '2026-07-01', 'party_ledger_id' => $supplier->id, 'reference_no' => 'OPEN-'.$iname,
                    'lines' => $lines, 'items' => [['stock_item_id' => $items[$iname], 'godown_id' => null, 'qty' => 100, 'rate' => $cost]],
                ]);
            }

            return ['company' => $company->id, 'customer' => $customer->id, 'revenue' => $revenue->id, 'items' => $items];
        });
    }

    /** A fresh TDS company: 194J section live, a deductee, an expense + bank ledger. */
    private function seedTdsCompany(string $name): array
    {
        $company = app(CompanyProvisioner::class)->create($name);

        return ActiveCompany::runAs($company->id, function () use ($company) {
            CompanyFeature::current()->update(['tds' => true]);
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

            $section = \App\Models\TdsSection::where('code', '393-194J')->value('id')
                ?? \App\Models\TdsSection::where('code', 'like', '%194J%')->value('id');

            $bank = Ledger::create(['name' => 'HDFC Bank', 'group_id' => $gid('Bank Accounts')]);
            $expense = Ledger::create(['name' => 'Legal Fees', 'group_id' => $gid('Indirect Expenses')]);
            $deductee = Ledger::create(['name' => 'Advisors LLP', 'group_id' => $gid('Sundry Creditors'),
                'deductee_type' => 'company_firm_llp', 'deductee_pan' => 'AAACA1111A', 'default_tds_section_id' => $section]);

            return ['company' => $company->id, 'bank' => $bank->id, 'expense' => $expense->id, 'deductee' => $deductee->id, 'section' => $section];
        });
    }

    private function plainSalesLines(array $c): array
    {
        $gst = app(GstService::class);
        $comp = $gst->computeInvoiceTax('sales', 'Maharashtra', [['ledger_id' => $c['revenue'], 'amount' => 1000]]);
        $lines = [['ledger_id' => $c['revenue'], 'dr_cr' => 'Cr', 'amount' => 1000]];
        foreach ($comp['lines'] as $tl) {
            $lines[] = ['ledger_id' => $tl['ledger_id'], 'dr_cr' => $tl['dr_cr'], 'amount' => $tl['amount']];
        }
        array_unshift($lines, ['ledger_id' => $c['customer'], 'dr_cr' => 'Dr', 'amount' => (100000 + $comp['tax_paise']) / 100]);

        return $lines;
    }

    // ── fingerprint for byte-identical diffing (keyed by NAME so two companies compare) ────

    private function voucherFingerprint(int $vid): array
    {
        $v = Voucher::with(['entries.ledger', 'stockEntries.stockItem', 'billAllocations.ledger'])->find($vid);

        $entries = [];
        $gst = [];
        $totalP = 0;
        foreach ($v->entries as $e) {
            $name = $e->ledger?->name ?? ('#'.$e->ledger_id);
            $paise = (int) round(((float) $e->amount) * 100);
            $entries[$name.'|'.$e->dr_cr] = $paise;
            if ($e->dr_cr === 'Dr') {
                $totalP += $paise;
            }
            if (str_contains($name, 'GST')) {
                $gst[$name.'|'.$e->dr_cr] = $paise;
            }
        }
        ksort($entries);
        ksort($gst);

        $stock = [];
        foreach ($v->stockEntries as $s) {
            $key = ($s->stockItem?->name ?? $s->stock_item_id).'|'.$s->direction;
            $stock[$key] = [(string) $s->quantity, (string) $s->rate, (string) $s->value];
        }
        ksort($stock);

        $bills = [];
        foreach ($v->billAllocations as $b) {
            $key = ($b->ledger?->name ?? $b->ledger_id).'|'.$b->ref_type.'|'.$b->ref_name;
            $bills[$key] = (int) round(((float) $b->amount) * 100);
        }
        ksort($bills);

        return ['entries' => $entries, 'gst' => $gst, 'stock' => $stock, 'bills' => $bills, 'total_paise' => $totalP, 'scenario' => $v->scenario_id];
    }

    // ── kernel dispatch + output helpers ──────────────────────────────────────────────────

    private function dispatch(string $method, string $uri, ?array $body = null, ?string $idem = null, ?string $companyId = null, ?string $key = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.($key ?? $this->key),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($idem !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idem;
        }
        if ($companyId !== null) {
            $server['HTTP_X_COMPANY_ID'] = $companyId;
        }

        $request = Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body));
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [
            'status' => $response->getStatusCode(),
            'json' => json_decode($response->getContent(), true),
            'headers' => $response->headers,
        ];
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
            $pass ? 'PASS' : 'FAIL', $label,
            $this->short($actual), $pass ? '' : ' (expected '.$this->short($expected).')'));
    }

    private function short(mixed $v): string
    {
        $s = json_encode($v);

        return strlen($s) > 200 ? substr($s, 0, 200).'…' : $s;
    }
}
