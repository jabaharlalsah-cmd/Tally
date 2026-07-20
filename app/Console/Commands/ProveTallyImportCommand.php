<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Models\AccountGroup;
use App\Models\BillAllocation;
use App\Models\CompanyFeature;
use App\Models\CostAllocation;
use App\Models\CostCentre;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Console\Concerns\RunsInTenantContext;
use App\Services\BalanceService;
use App\Services\TallyImport\BulkMode;
use App\Services\TallyImport\TallyImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7A numeric proof of the Tally-Data Migration tool.
 *
 * Runs the REAL importer end to end against synthetic Tally exports and asserts
 * every figure to the paise / unit:
 *
 *   • a DRY-RUN previews the full summary and leaves the DB byte-for-byte unchanged;
 *   • a REAL import creates EXACTLY the right masters (reusing seeded groups/ledgers/
 *     godown — no duplicates), carries openings with the correct Dr/Cr direction, and
 *     balances the Trial Balance AND Balance Sheet (Stock-in-Hand included);
 *   • the Sale's stock OUT locked its cost at the running weighted average — proof
 *     the vouchers were posted in strict chronological order (the file has the Sale
 *     BEFORE the Purchase; a naive importer would lock the wrong cost);
 *   • every ledger / item / voucher round-trips against the source XML to the paise;
 *   • a deliberately unbalanced voucher is REJECTED by name and the whole import
 *     rolled back;
 *   • a few-hundred-voucher export imports in seconds (streaming + bulk-mode path),
 *     and the bulk-mode flag does NOT persist afterwards.
 *
 * Everything runs inside one outer transaction: the importer's own commit becomes a
 * savepoint release (so a "committed" run is inspectable) and this command rolls the
 * whole thing back at the end — leaving the DB exactly as it found it (unless --keep).
 */
class ProveTallyImportCommand extends Command
{
    use RunsInTenantContext;
    use ResolvesActiveCompany;

    protected $signature = 'zerobook:prove-tally-import
        {--keep : keep the imported sample in the DB}
        {--company= : run in this company (slug or id); default = the tenant’s default company}
        {--tenant= : the tenant subdomain whose database to prove against}';

    protected $description = 'Import synthetic Tally exports end-to-end and prove masters, openings, chronological COGS, balance, dry-run, rejection and bulk performance';

    private bool $ok = true;

    public function handle(TallyImporter $importer, BalanceService $bs): int
    {
        // Proving the importer writes accounting data — scope it to a tenant DB.
        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        return $scope(fn () => $this->runProof($importer, $bs));
    }

    private function runProof(TallyImporter $importer, BalanceService $bs): int
    {
        // Phase 12A — pin the tenant's default company as active (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            return self::FAILURE;
        }

        $sampleDir = base_path('_docs/tally-samples');
        $proveXml = $sampleDir.'/prove.xml';
        $brokenXml = $sampleDir.'/broken.xml';
        $largeXml = storage_path('app/tally-large-sample.xml');

        DB::beginTransaction();
        try {
            // ── clean slate ──────────────────────────────────────────────────
            $this->clearTestData();
            CompanyFeature::current()->update([
                'gst' => false, 'vat' => false, 'bill_by_bill' => false,
                'cost_centres' => false, 'multi_currency' => false,
            ]);
            Ledger::where('name', 'Cash')->update(['opening_balance' => 0, 'opening_balance_type' => null]);

            $seededGroups = AccountGroup::count();          // 28
            $reservedLedgers = Ledger::where('is_reserved', true)->count();

            // ═════════════ TEST A — DRY-RUN leaves the DB byte-identical ═══════
            $this->section('A. DRY-RUN of prove.xml — full preview, zero writes');
            $before = $this->snapshot();
            $a = $importer->run($proveXml, dryRun: true);

            $this->expect('Dry-run parsed 4 vouchers', $a->rawVoucherCount, 4);
            $this->expect('Dry-run "posted" 4 in preview', $a->vouchersPosted, 4);
            $this->expect('Dry-run detected out-of-order source', $a->reordered, true);
            $this->expect('Dry-run TB balanced', $a->tbBalanced, true);
            $this->expect('Dry-run TB Dr = 53,700', $a->tbDr, 5370000);
            $this->expect('Dry-run TB Cr = 53,700', $a->tbCr, 5370000);
            $this->expect('Dry-run Net Profit = 500', $a->netProfit, 50000);
            $this->expect('Dry-run Stock-in-Hand = 2,800', $a->bsClosingStock, 280000);
            $this->expect('Dry-run Balance Sheet balanced', $a->bsBalanced, true);
            $this->expect('Dry-run BS opening-diff = 1,000 (= opening stock)', $a->bsLiabDiff, 100000);
            $this->expect('Dry-run groups: 3 created', $a->entityCounts['groups']['created'] ?? null, 3);
            $this->expect('Dry-run groups: 6 reused (seeded)', $a->entityCounts['groups']['reused'] ?? null, 6);
            $this->expect('Dry-run ledgers: 5 created', $a->entityCounts['ledgers']['created'] ?? null, 5);
            $this->expect('Dry-run ledgers: 1 reused (Cash)', $a->entityCounts['ledgers']['reused'] ?? null, 1);
            $this->expect('Dry-run godown: 1 reused (Main Location)', $a->entityCounts['godowns']['reused'] ?? null, 1);
            $this->expect('Dry-run reported "not imported" concepts', count($a->notImported) >= 2, true);
            $this->expect('Dry-run committed = false', $a->committed, false);

            $after = $this->snapshot();
            $this->expect('DRY-RUN wrote NOTHING (row counts identical)', $after === $before, true);
            $this->expect('DRY-RUN left Cash opening untouched (0)', (float) Ledger::where('name', 'Cash')->value('opening_balance'), 0.0);

            // ═════════════ TEST B — a deliberately unbalanced voucher is rejected
            $this->section('B. BROKEN export (Dr ≠ Cr) — rejected by name, rolled back');
            $vBefore = Voucher::count();
            $b = $importer->run($brokenXml, dryRun: false);
            $this->expect('Broken import NOT committed', $b->committed, false);
            $this->expect('Broken import has exactly 1 rejection', count($b->rejections), 1);
            $rej = $b->rejections[0] ?? ['voucher' => '', 'reason' => ''];
            $this->expect('Rejection names the Journal', str_contains($rej['voucher'], 'Journal'), true);
            $this->expect('Rejection reason cites the balance gate', str_contains(strtolower($rej['reason']), 'balance'), true);
            $this->expect('Broken import wrote no vouchers (rolled back)', Voucher::count(), $vBefore);
            $this->expect('Broken import wrote no ledgers (rolled back)', Ledger::where('name', 'Broken Expense')->exists(), false);

            // ═════════════ TEST C — a few-hundred-voucher export, streaming + bulk
            $this->section('C. LARGE export (streaming + bulk-mode performance path)');
            $n = 300;
            file_put_contents($largeXml, $this->largeSampleXml($n));
            $c = $importer->run($largeXml, dryRun: true); // dry-run: exercises the full post path, auto-cleans
            $this->expect("Large import posted all {$n} vouchers", $c->vouchersPosted, $n);
            $this->expect('Large import re-sorted a reverse-ordered file', $c->reordered, true);
            $this->expect('Large import had no rejections', $c->hasRejections(), false);
            $seconds = $c->totalMs / 1000;
            $this->expect(sprintf('Large import finished in seconds not hours (%.2fs < 30s)', $seconds), $seconds < 30, true);
            $this->line(sprintf('     · %d vouchers in %.0f ms  (%.2f ms/voucher, streaming)', $n, $c->totalMs, $c->totalMs / $n));
            @unlink($largeXml);

            // bulk-mode flag must NOT survive an import
            $this->expect('Bulk-mode flag does NOT persist after import', BulkMode::isActive(), false);

            // ═════════════ TEST D — the REAL import, then inspect the committed book
            $this->section('D. REAL import of prove.xml — inspect the committed book');
            $d = $importer->run($proveXml, dryRun: false);
            $this->expect('Real import committed', $d->committed, true);
            $this->expect('Real import had no rejections', $d->hasRejections(), false);
            $this->expect('Bulk-mode flag still not persisting', BulkMode::isActive(), false);

            // masters: exactly the seeded groups + 3 custom, no duplicates
            $this->expect('Account groups = seeded + 3 custom', AccountGroup::count(), $seededGroups + 3);
            $this->expect('No duplicate group names', AccountGroup::count() === AccountGroup::distinct('name')->count('name'), true);
            $this->expect('Reserved ledgers untouched in count', Ledger::where('is_reserved', true)->count(), $reservedLedgers);
            $this->expect('5 custom ledgers created', Ledger::where('is_reserved', false)->count(), 5);
            $this->expect('Main Location NOT duplicated', Godown::where('name', 'Main Location')->count(), 1);
            $this->expect('Widget + Gadget imported', StockItem::count(), 2);
            $this->expect('North Sales nests under Sales Division under Sales Accounts',
                $this->groupPath('North Sales'), 'Sales Accounts ▸ Sales Division ▸ North Sales');

            // openings — magnitude AND Dr/Cr direction
            $this->expectOpening('Cash opening Dr 31,000 (reused ledger carried its opening)', 'Cash', 31000, 'Dr');
            $this->expectOpening('Rajan Capital opening Cr 51,000', 'Rajan Capital A/c', 51000, 'Cr');
            $this->expectOpening('ABC Retail opening Dr 20,000', 'ABC Retail', 20000, 'Dr');
            $this->expect('ABC Retail carried GSTIN', Ledger::where('name', 'ABC Retail')->value('gstin'), '27AAACA1111A1Z1');
            $this->expect('ABC Retail carried state', Ledger::where('name', 'ABC Retail')->value('state'), 'Maharashtra');
            $this->expect('Modern Traders flagged bill-by-bill', (bool) Ledger::where('name', 'Modern Traders')->value('maintain_bill_by_bill'), true);
            $this->expect('Trade Purchases flagged cost-centres', (bool) Ledger::where('name', 'Trade Purchases')->value('cost_centres_applicable'), true);

            // stock item opening
            $widget = StockItem::where('name', 'Widget')->first();
            $this->expectClose('Widget opening qty 40', (float) $widget->opening_qty, 40);
            $this->expectClose('Widget opening value 1,000', (float) $widget->opening_value, 1000);

            // vouchers — round-trip to the paise
            $this->expect('4 vouchers posted', Voucher::count(), 4);
            $sale = Voucher::where('type', 'sales')->first();
            $purchase = Voucher::where('type', 'purchase')->first();
            $payment = Voucher::where('type', 'payment')->first();
            $journal = Voucher::where('type', 'journal')->first();

            $this->expect('Sales dated 2026-04-05', $sale->date->toDateString(), '2026-04-05');
            $this->expectLeg('Sales: ABC Retail Dr 2,700', $sale, 'ABC Retail', 'Dr', 270000);
            $this->expectLeg('Sales: Trade Sales Cr 2,700', $sale, 'Trade Sales', 'Cr', 270000);
            $this->expect('Purchase dated 2026-04-03', $purchase->date->toDateString(), '2026-04-03');
            $this->expectLeg('Purchase: Modern Traders Cr 3,000', $purchase, 'Modern Traders', 'Cr', 300000);
            $this->expectLeg('Purchase: Trade Purchases Dr 3,000', $purchase, 'Trade Purchases', 'Dr', 300000);
            $this->expectLeg('Payment: Modern Traders Dr 5,000', $payment, 'Modern Traders', 'Dr', 500000);
            $this->expectLeg('Payment: Cash Cr 5,000', $payment, 'Cash', 'Cr', 500000);
            $this->expectLeg('Journal: Trade Purchases Dr 1,000', $journal, 'Trade Purchases', 'Dr', 100000);
            $this->expectLeg('Journal: Modern Traders Cr 1,000', $journal, 'Modern Traders', 'Cr', 100000);

            // THE chronological-order proof: the Sale's OUT cost is the weighted
            // average AFTER the Purchase (opening 40@25 + buy 60@50 = 100 @ 40), NOT
            // the opening-only 25 a naive (unsorted) import would have locked.
            $se = StockEntry::where('voucher_id', $sale->id)->first();
            $this->expect('Sale stock row direction OUT', $se->direction, 'out');
            $this->expectClose('Sale COST rate 40 (weighted-avg AFTER purchase — proves chronological order)', (float) $se->rate, 40);
            $this->expectClose('Sale COST value 1,200 (30 × 40)', (float) $se->value, 1200);
            $this->expectClose('Sale SELL rate 90 (unchanged)', (float) $se->sale_rate, 90);
            $this->expectClose('Sale SELL value 2,700', (float) $se->sale_value, 2700);
            $close = app(\App\Services\StockService::class)->closingBalance($widget->id, Carbon::create(2027, 3, 31));
            $this->expectClose('Widget closing qty 70', $close['qty'], 70);
            $this->expectClose('Widget closing value 2,800', $close['value'], 2800);

            // bill-wise + cost allocations carried
            $this->expect('Modern Traders has 3 bill references (PB-100, ADV-1, PB-101)',
                BillAllocation::whereIn('ref_name', ['PB-100', 'ADV-1', 'PB-101'])->count(), 3);
            $this->expect('Cost allocations to North Region posted', CostAllocation::count() >= 2, true);

            // recomputed Trial Balance / Balance Sheet on the committed data
            [$from, $to] = [Carbon::create(2026, 4, 1), Carbon::create(2027, 3, 31)];
            $tb = $bs->trialBalance($from, $to);
            $sheet = $bs->balanceSheet($from, $to);
            $pl = $bs->profitAndLoss($from, $to);
            $this->expect('Committed TB balanced', $tb['balanced'], true);
            $this->expect('Committed TB Dr = Cr = 53,700', $tb['total_dr'] === 5370000 && $tb['total_cr'] === 5370000, true);
            $this->expect('Committed Balance Sheet balanced', $sheet['balanced'], true);
            $this->expect('Committed Net Profit 500', $pl['net'], 50000);
            $this->expect('Committed Stock-in-Hand 2,800', $sheet['closing_stock'], 280000);

            // ── report ───────────────────────────────────────────────────────
            $this->line('');
            foreach ($d->lines() as $l) {
                $this->line($l);
            }
        } finally {
            if ($this->option('keep')) {
                DB::commit();
                $this->line('');
                $this->info($this->ok ? 'ALL ASSERTIONS PASSED — sample kept in DB.' : 'ASSERTIONS FAILED — sample kept for inspection.');
            } else {
                DB::rollBack();
                $this->line('');
                $this->info($this->ok ? 'ALL ASSERTIONS PASSED — rolled back (DB left exactly as found).' : 'ASSERTIONS FAILED — rolled back.');
            }
            @unlink($largeXml);
        }

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function clearTestData(): void
    {
        StockEntry::query()->delete();
        BillAllocation::query()->delete();
        CostAllocation::query()->delete();
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        StockItem::query()->delete();
        StockGroup::query()->delete();
        Unit::query()->delete();
        CostCentre::query()->delete();
        Godown::where('is_reserved', false)->delete();
        Ledger::where('is_reserved', false)->delete();
        // Non-reserved (custom) account groups, deepest first so no parent is removed
        // out from under a child (parent_id is restrictOnDelete).
        foreach (AccountGroup::where('is_reserved', false)->get()->sortByDesc(fn ($g) => $g->depth()) as $g) {
            $g->delete();
        }
    }

    /** A comparable snapshot of the row counts that an import would touch. */
    private function snapshot(): array
    {
        return [
            'groups' => AccountGroup::count(),
            'ledgers' => Ledger::count(),
            'vouchers' => Voucher::count(),
            'entries' => VoucherEntry::count(),
            'items' => StockItem::count(),
            'stock_groups' => StockGroup::count(),
            'units' => Unit::count(),
            'godowns' => Godown::count(),
            'cost_centres' => CostCentre::count(),
            'stock_entries' => StockEntry::count(),
            'bills' => BillAllocation::count(),
            'costs' => CostAllocation::count(),
        ];
    }

    private function groupPath(string $name): string
    {
        $g = AccountGroup::where('name', $name)->first();

        return $g ? $g->pathLabel() : '(missing)';
    }

    /** N balanced Journal vouchers between two ledgers, emitted in REVERSE date order. */
    private function largeSampleXml(int $n): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<ENVELOPE><BODY><IMPORTDATA><REQUESTDATA>';
        $s .= '<TALLYMESSAGE><GROUP NAME="Suspense A/c" RESERVEDNAME="Suspense A/c"><NAME.LIST><NAME>Suspense A/c</NAME></NAME.LIST><PARENT/></GROUP></TALLYMESSAGE>';
        $s .= '<TALLYMESSAGE><LEDGER NAME="Bulk Ledger A"><NAME.LIST><NAME>Bulk Ledger A</NAME></NAME.LIST><PARENT>Suspense A/c</PARENT></LEDGER></TALLYMESSAGE>';
        $s .= '<TALLYMESSAGE><LEDGER NAME="Bulk Ledger B"><NAME.LIST><NAME>Bulk Ledger B</NAME></NAME.LIST><PARENT>Suspense A/c</PARENT></LEDGER></TALLYMESSAGE>';
        $base = Carbon::create(2026, 4, 1);
        for ($i = $n; $i >= 1; $i--) { // reverse chronological → forces the sort to work
            $date = $base->copy()->addDays($i - 1)->format('Ymd');
            $amt = 100 + ($i % 50);
            $s .= '<TALLYMESSAGE><VOUCHER VCHTYPE="Journal" ACTION="Create">'
                .'<DATE>'.$date.'</DATE><VOUCHERTYPENAME>Journal</VOUCHERTYPENAME><VOUCHERNUMBER>'.$i.'</VOUCHERNUMBER>'
                .'<NARRATION>Bulk voucher '.$i.'</NARRATION>'
                .'<ALLLEDGERENTRIES.LIST><LEDGERNAME>Bulk Ledger A</LEDGERNAME><ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE><AMOUNT>-'.$amt.'.00</AMOUNT></ALLLEDGERENTRIES.LIST>'
                .'<ALLLEDGERENTRIES.LIST><LEDGERNAME>Bulk Ledger B</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>'.$amt.'.00</AMOUNT></ALLLEDGERENTRIES.LIST>'
                .'</VOUCHER></TALLYMESSAGE>';
        }
        $s .= '</REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

        return $s;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line('── '.$title.' '.str_repeat('─', max(0, 60 - strlen($title))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected '.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function expectClose(string $label, $actual, $expected): void
    {
        $pass = is_numeric($actual) && abs((float) $actual - (float) $expected) < 0.005;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected ~'.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function expectOpening(string $label, string $ledger, float $mag, string $side): void
    {
        $l = Ledger::where('name', $ledger)->first();
        $okMag = $l && abs((float) $l->opening_balance - $mag) < 0.005;
        $okSide = $l && $l->opening_balance_type === $side;
        $pass = $okMag && $okSide;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.($l ? $l->opening_balance.' '.$l->opening_balance_type : 'missing'));
        $this->ok = $this->ok && $pass;
    }

    private function expectLeg(string $label, Voucher $v, string $ledgerName, string $side, int $paise): void
    {
        $leg = null;
        foreach ($v->entries()->with('ledger')->get() as $e) {
            if ($e->ledger && $e->ledger->name === $ledgerName) {
                $leg = ['side' => $e->dr_cr, 'paise' => (int) round(((float) $e->amount) * 100)];
                break;
            }
        }
        $pass = $leg !== null && $leg['side'] === $side && $leg['paise'] === $paise;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.($leg ? $leg['side'].' '.BalanceService::money($leg['paise']) : 'missing'));
        $this->ok = $this->ok && $pass;
    }

    private function fmt($v): string
    {
        return is_bool($v) ? ($v ? 'true' : 'false') : var_export($v, true);
    }
}
