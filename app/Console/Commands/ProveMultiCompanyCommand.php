<?php

namespace App\Console\Commands;

use App\Livewire\CompanyWorkspace;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\CompanyFeature;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockItem;
use App\Models\TdsSection;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\BalanceService;
use App\Services\CompanyProvisioner;
use App\Services\GstService;
use App\Services\Sync\SyncService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Phase 12A — THE multi-company proof: two companies A and B (and a calendar-FY
 * company C) inside ONE tenant database, with ZERO cross-company leakage. It
 * asserts, to the row:
 *
 *   • provisioning creates ONE default company owning every seeded row;
 *   • zerobook:company-create seeds a complete, isolated chart for company B;
 *   • the same master names coexist per company; F11 flags and identity are
 *     per-company; a voucher in A never appears in B's Day Book or Trial Balance;
 *   • voucher NUMBERING is per-company (both companies hold Payment №1);
 *   • a payload naming another company's ledger id is REJECTED server-side;
 *   • currencies/stock/sync are company-scoped, and the sync change-log carries
 *     company_id at the ROW level;
 *   • the Tally importer REFUSES to run without --company;
 *   • a company with financial_year_start_month = 1 buckets its books FY by
 *     calendar year while the STATUTORY (TDS) FY stays Apr–Mar;
 *   • deactivation guards hold (never the active company, never the last one);
 *   • the stale-tab guard 409s a Livewire request whose company changed;
 *   • the existing engine proofs run green INSIDE the multi-company tenant.
 */
class ProveMultiCompanyCommand extends Command
{
    protected $signature = 'zerobook:prove-multi-company {--keep : keep the mcproof tenant provisioned}';

    protected $description = 'Prove Phase 12A: full data isolation between companies in one tenant — masters, vouchers, numbering, F11, currencies, sync, importer scoping, per-company FY';

    private bool $ok = true;

    private int $a;

    private int $b;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'mcproof';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'MC Proof & Co', 'professional');
            Tenant::find($slug)->run(fn () => $this->runProof());
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if (! $this->option('keep')) {
                $provisioner->teardown($slug);
            }
        }

        $this->line('');
        $this->info($this->ok ? 'ALL MULTI-COMPANY ASSERTIONS PASSED.' : 'MULTI-COMPANY ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        // ═══ 0. Provisioning created exactly one default company ═══════════════
        $this->section('Default company (fresh provision)');
        $this->expect('Exactly one company exists', Company::count(), 1);
        $default = Company::defaultCompany();
        $this->expect('Named after the tenant', $default->name, 'MC Proof & Co');
        $this->expect('Slug derived', $default->slug, 'mc-proof-co');
        $this->expect('FY starts April by default', (int) $default->financial_year_start_month, 4);
        $this->a = $default->id;
        ActiveCompany::set($this->a);
        $this->expect('Every seeded group belongs to it', AccountGroup::count(), 28);
        $this->expect('Seeded ledgers belong to it', Ledger::count(), 15);
        $this->expect('Fresh F11 row is all-off', CompanyFeature::current()->gst, false);

        // ═══ 1. Company B via the artisan command ═══════════════════════════════
        $this->section('zerobook:company-create seeds a complete second chart');
        $code = Artisan::call('zerobook:company-create', ['--name' => 'Beta Client Books']);
        $this->expect('company-create exits SUCCESS', $code, self::SUCCESS);
        $beta = Company::where('name', 'Beta Client Books')->first();
        $this->expect('Company B exists', $beta !== null, true);
        $this->b = $beta->id;

        ActiveCompany::runAs($this->b, function () {
            $this->expect('[B] 28 account groups', AccountGroup::count(), 28);
            $this->expect('[B] 15 seeded ledgers (Cash, P&L, duty, returns, forex)', Ledger::count(), 15);
            $this->expect('[B] 15 TDS sections', TdsSection::count(), 15);
            $this->expect('[B] INR base currency', Currency::base()?->code, 'INR');
            $this->expect('[B] Main Location godown', Godown::where('name', 'Main Location')->exists(), true);
            // NOTE: this array is an exact-match assertion, so ADDING ANY F11 FLAG
            // requires updating it here. 'inventory' was added by the NAS parity
            // Gateway rearrangement.
            $this->expect('[B] fresh all-off F11', CompanyFeature::current()->toFlags(), [
                'bill_by_bill' => false, 'cost_centres' => false, 'gst' => false,
                'vat' => false, 'tds' => false, 'multi_currency' => false,
                'inventory' => false, 'budgets' => false,
                'ratio_analysis' => false, 'scenarios' => false,
            ]);
        });
        ActiveCompany::runAs($this->a, function () {
            $this->expect('[A] untouched by B\'s seeding — still 28 groups', AccountGroup::count(), 28);
            $this->expect('[A] still 15 ledgers', Ledger::count(), 15);
        });

        // ═══ 2. Same master NAMES coexist per company ═══════════════════════════
        $this->section('Same-name masters in both companies');
        $bankA = ActiveCompany::runAs($this->a, fn () => Ledger::create([
            'name' => 'HDFC Bank', 'group_id' => AccountGroup::where('name', 'Bank Accounts')->value('id'),
        ]));
        $bankB = ActiveCompany::runAs($this->b, fn () => Ledger::create([
            'name' => 'HDFC Bank', 'group_id' => AccountGroup::where('name', 'Bank Accounts')->value('id'),
        ]));
        $this->expect('"HDFC Bank" created in A', $bankA->exists, true);
        $this->expect('"HDFC Bank" created in B too (composite unique)', $bankB->exists, true);
        $this->expect('Different rows', $bankA->id !== $bankB->id, true);
        $this->expect('Rows stamped with their companies', [$bankA->company_id, $bankB->company_id], [$this->a, $this->b]);

        // ═══ 3. F11 flags + identity are per-company ════════════════════════════
        $this->section('F11 and identity isolation');
        ActiveCompany::runAs($this->a, function () {
            CompanyFeature::current()->update(['gst' => true, 'bill_by_bill' => true]);
            activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);
        });
        ActiveCompany::runAs($this->b, function () {
            $this->expect('[B] GST still OFF after A enabled it', CompanyFeature::current()->gst, false);
            $this->expect('[B] no GSTIN', activeCompany()->gstin, null);
            $this->expect('[B] no state', activeCompany()->state, null);
            $this->expect('[B] GstService disabled', app(GstService::class)->enabled(), false);
        });
        ActiveCompany::runAs($this->a, function () {
            $this->expect('[A] GstService enabled with its own state', app(GstService::class)->companyState(), 'Maharashtra');
        });

        // ═══ 4. A voucher in A is invisible in B ════════════════════════════════
        $this->section('Voucher isolation — post in A, look in B');
        $vA = ActiveCompany::runAs($this->a, function () {
            $exp = Ledger::create(['name' => 'Office Expense', 'group_id' => AccountGroup::where('name', 'Indirect Expenses')->value('id')]);
            $res = (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-07-10', 'narration' => 'A only', 'lines' => [
                ['ledger_id' => $exp->id, 'dr_cr' => 'Dr', 'amount' => 1500],
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 1500],
            ]]);

            return $res['voucher'];
        });
        $this->expect('[A] Payment posted as №1', $vA['number'], 1);
        $this->expect('[A] Day Book has 1 voucher', ActiveCompany::runAs($this->a, fn () => Voucher::count()), 1);
        $this->expect('[B] Day Book EMPTY', ActiveCompany::runAs($this->b, fn () => Voucher::count()), 0);
        $this->expect('[B] no voucher entries either', ActiveCompany::runAs($this->b, fn () => \App\Models\VoucherEntry::count()), 0);
        $rowLevel = DB::table('vouchers')->where('company_id', $this->b)->count();
        $this->expect('Row-level check: zero vouchers stamped B', $rowLevel, 0);

        // ═══ 5. Numbering is PER COMPANY ════════════════════════════════════════
        $this->section('Per-company voucher numbering');
        $vB = ActiveCompany::runAs($this->b, function () {
            $exp = Ledger::create(['name' => 'Office Expense', 'group_id' => AccountGroup::where('name', 'Indirect Expenses')->value('id')]);

            return (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-07-10', 'narration' => 'B first', 'lines' => [
                ['ledger_id' => $exp->id, 'dr_cr' => 'Dr', 'amount' => 900],
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 900],
            ]])['voucher'];
        });
        $this->expect('[B] its first Payment is ALSO №1 (same type, same FY)', $vB['number'], 1);
        $this->expect('[A] next number is 2, undisturbed by B', ActiveCompany::runAs($this->a, fn () => Voucher::nextNumber('payment', 2026)), 2);

        // ═══ 6. A payload naming ANOTHER company's ledger is rejected ═══════════
        $this->section('Server rejects cross-company references');
        $rejected = false;
        try {
            ActiveCompany::runAs($this->b, fn () => (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-07-10', 'lines' => [
                ['ledger_id' => $bankA->id, 'dr_cr' => 'Dr', 'amount' => 100], // A's ledger id!
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 100],
            ]]));
        } catch (ValidationException) {
            $rejected = true;
        }
        $this->expect('Posting in B with A\'s ledger id is REJECTED', $rejected, true);

        // ═══ 7. Trial Balance balances per company, independently ═══════════════
        $this->section('Per-company Trial Balance');
        [$tbA, $tbB] = [
            ActiveCompany::runAs($this->a, fn () => app(BalanceService::class)->trialBalance(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31'))),
            ActiveCompany::runAs($this->b, fn () => app(BalanceService::class)->trialBalance(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31'))),
        ];
        $this->expect('[A] TB balanced', $tbA['balanced'], true);
        $this->expect('[B] TB balanced', $tbB['balanced'], true);
        $this->expect('[A] TB total = its own 1,500', $tbA['total_dr'], 150000);
        $this->expect('[B] TB total = its own 900', $tbB['total_dr'], 90000);

        // ═══ 8. Currencies are per-company ══════════════════════════════════════
        $this->section('Currency isolation');
        ActiveCompany::runAs($this->a, function () {
            $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_base' => false]);
            ExchangeRate::create(['currency_id' => $usd->id, 'date' => '2026-07-01', 'rate' => 83.5]);
        });
        $this->expect('[B] still sees ONLY INR', ActiveCompany::runAs($this->b, fn () => Currency::pluck('code')->all()), ['INR']);
        // B re-affirms its base — the scoped bulk update must not touch A.
        ActiveCompany::runAs($this->b, function () {
            Currency::query()->update(['is_base' => false]);
            Currency::where('code', 'INR')->update(['is_base' => true]);
        });
        ActiveCompany::runAs($this->a, function () {
            $this->expect('[A] INR base flag SURVIVED B\'s bulk update', Currency::base()?->code, 'INR');
            $this->expect('[A] USD row intact', Currency::where('code', 'USD')->exists(), true);
        });

        // ═══ 9. Stock is per-company ════════════════════════════════════════════
        $this->section('Inventory isolation');
        ActiveCompany::runAs($this->a, function () {
            $u = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
            StockItem::create(['name' => 'Widget', 'unit_id' => $u->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]);
        });
        $this->expect('[B] no stock items (stockEnabled false)', ActiveCompany::runAs($this->b, fn () => StockItem::exists()), false);

        // ═══ 10. Sync scoping — change-log rows + snapshot ══════════════════════
        $this->section('Sync isolation');
        $logA = DB::table('sync_changes')->where('entity', 'voucher')->whereNull('company_id')->count();
        $this->expect('Every change-log row carries a company_id', $logA, 0);
        $snapB = ActiveCompany::runAs($this->b, fn () => (new SyncService())->pull(0));
        $this->expect('[B] snapshot ships only B\'s vouchers', count($snapB['vouchers']), 1);
        $this->expect('[B] snapshot ships only B\'s ledgers (15 seeded + bank + expense)', count($snapB['masters']['ledgers']), 17);
        $incB = ActiveCompany::runAs($this->b, fn () => (new SyncService())->pull(1));
        $leaked = collect($incB['vouchers'])->contains(fn ($v) => ($v['voucher']['narration'] ?? '') === 'A only');
        $this->expect('[B] incremental pull never leaks A\'s changes', $leaked, false);

        // ═══ 11. The importer refuses without --company ═════════════════════════
        $this->section('Importer scoping');
        $code = Artisan::call('zerobook:tally-import', ['file' => base_path('_docs/tally-samples/prove.xml'), '--dry-run' => true]);
        $this->expect('tally-import without --company REFUSED', $code, self::FAILURE);

        // ═══ 12. Per-company books FY (calendar year) vs statutory FY ═══════════
        $this->section('financial_year_start_month = 1 (calendar-year books)');
        $cal = app(CompanyProvisioner::class)->create('Calendar Books Co', null, ['financial_year_start_month' => 1]);
        ActiveCompany::runAs($cal->id, function () {
            $feb = Carbon::parse('2026-02-10');
            $this->expect('[C] books FY of 10-Feb-2026 is 2026', Voucher::fyStartFor($feb), 2026);
            $this->expect('[C] books FY label is plain "2026"', Voucher::fyLabel(2026), '2026');
            $this->expect('[C] STATUTORY FY of the same date stays 2025 (Apr–Mar)', Voucher::statutoryFyStartFor($feb), 2025);
            $exp = Ledger::create(['name' => 'Office Expense', 'group_id' => AccountGroup::where('name', 'Indirect Expenses')->value('id')]);
            $res = (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-02-10', 'lines' => [
                ['ledger_id' => $exp->id, 'dr_cr' => 'Dr', 'amount' => 100],
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 100],
            ]]);
            $this->expect('[C] voucher stored under books fy_start 2026', Voucher::find($res['voucher']['id'])->fy_start, 2026);
        });
        ActiveCompany::runAs($this->a, function () {
            $this->expect('[A] April-FY company still buckets Feb-2026 into 2025', Voucher::fyStartFor(Carbon::parse('2026-02-10')), 2025);
        });

        // ═══ 13. Deactivation guards ════════════════════════════════════════════
        $this->section('Deactivation guards (Companies screen)');
        ActiveCompany::set($this->a);
        $ws = new CompanyWorkspace();
        $res = $ws->deleteMaster($this->a);
        $this->expect('Cannot deactivate the ACTIVE company', $res['ok'], false);
        $res = $ws->deleteMaster($cal->id);
        $this->expect('Deactivating an idle company works', $res['ok'], true);
        $this->expect('Its books survive deactivation', DB::table('vouchers')->where('company_id', $cal->id)->count(), 1);
        // Deactivate B, then the guard must protect the LAST active company (A).
        $ws->deleteMaster($this->b);
        $res = ActiveCompany::runAs($this->b, fn () => (new CompanyWorkspace())->deleteMaster($this->a));
        $this->expect('Cannot deactivate the LAST active company', $res['ok'], false);
        // restore B for the remaining sections
        Company::whereKey($this->b)->update(['is_active' => true]);

        // ═══ 14. The stale-tab guard ════════════════════════════════════════════
        $this->section('Stale-tab guard (Livewire hydrate)');
        ActiveCompany::set($this->a);
        $screen = new VoucherScreen();
        $screen->guardCompanyId = $this->a;
        $status = null;
        try {
            ActiveCompany::runAs($this->b, fn () => $screen->hydrateGuardsActiveCompany());
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
        }
        $this->expect('A component mounted under A aborts 409 once B is active', $status, 409);

        // ═══ 15. The engine proofs run green INSIDE this multi-company tenant ═══
        $this->section('Engine proofs inside the multi-company tenant');
        foreach (['prove-balance', 'prove-gst'] as $p) {
            $code = Artisan::call('zerobook:'.$p);
            $this->expect("zerobook:{$p} (own fresh company) exits green", $code, self::SUCCESS);
        }
        $this->expect('Their throwaway companies rolled back with their transactions', Company::count(), 3);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ".str_repeat('─', max(1, 60 - mb_strlen($title))));
    }

    private function expect(string $label, mixed $actual, mixed $expected): void
    {
        $pass = $actual === $expected;
        if (! $pass) {
            $this->ok = false;
        }
        $this->line(sprintf(' [%s] %s = %s%s',
            $pass ? 'PASS' : 'FAIL', $label, json_encode($actual),
            $pass ? '' : ' (expected '.json_encode($expected).')'));
    }
}
