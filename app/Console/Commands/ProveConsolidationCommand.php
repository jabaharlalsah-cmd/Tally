<?php

namespace App\Console\Commands;

use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Currency;
use App\Models\Godown;
use App\Models\StockLot;
use App\Models\Ledger;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\BalanceService;
use App\Services\CompanyProvisioner;
use App\Services\GroupConsolidationService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 12C-2 — THE consolidation proof, the checklist's canonical scenario
 * asserted to the paise:
 *
 *   A buys 100 widgets @₹100 outside (₹10,000). A sells all 100 to B @₹120
 *   (₹12,000, tagged). B sells 30 to an outside customer @₹150 (₹4,500).
 *
 *   → Group P&L: sales ₹4,500 (A's ₹12,000 eliminated), purchases ₹10,000
 *     (B's ₹12,000 eliminated); unrealised elimination 70 × ₹20 = ₹1,400;
 *     group stock ₹7,000 (not B's ₹8,400); group net ₹1,500 = ΣNets ₹2,900
 *     − ₹1,400; the A→B receivable and B→A payable (₹12,000 each) vanish;
 *     Group TB and BS balance; P&L net ties into the BS.
 *
 * Plus: an UNTAGGED pre-group voucher surfaced (never silently double-counted);
 * an UNMATCHED lot listed and NOT eliminated; drill data present; single-member
 * group ≡ the member's individual reports; mixed-base-currency group refused;
 * every per-company Trial Balance still balanced. Consolidation posts NOTHING.
 */
class ProveConsolidationCommand extends Command
{
    protected $signature = 'zerobook:prove-consolidation {--keep : keep the constest tenant provisioned}';

    protected $description = 'Prove Phase 12C-2: group consolidation with complete + unrealised-profit elimination — worked numbers, tie checks, unmatched/untagged surfacing, and read-only guarantees';

    private bool $ok = true;

    private int $a;

    private int $b;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'constest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Apex Holdings', 'professional');
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
        $this->info($this->ok ? 'ALL CONSOLIDATION ASSERTIONS PASSED.' : 'CONSOLIDATION ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        // A FRESH service per report view — mirrors real usage (one request =
        // one resolved instance) and defeats the per-request memo across the
        // proof's mutations between views.
        $svc = fn () => app(GroupConsolidationService::class);
        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2027-03-31');

        $this->a = Company::defaultCompany()->id;
        $this->b = app(CompanyProvisioner::class)->create('Bravo Retail Co')->id;

        // Masters. Item names match across companies (the 12C-1 identity).
        [$aDebtorB, $aWidget, $aSales, $aPurchase, $aSupplier] = ActiveCompany::runAs($this->a, function () {
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);

            return [
                Ledger::create(['name' => 'Bravo Retail Co', 'group_id' => $gid('Sundry Debtors'), 'linked_company_id' => $this->b]),
                StockItem::create(['name' => 'Widget', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]),
                Ledger::create(['name' => 'Sales A/c', 'group_id' => $gid('Sales Accounts')]),
                Ledger::create(['name' => 'Purchase A/c', 'group_id' => $gid('Purchase Accounts')]),
                Ledger::create(['name' => 'Outside Supplies Ltd', 'group_id' => $gid('Sundry Creditors')]),
            ];
        });
        $bL = ActiveCompany::runAs($this->b, function () {
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $unit = Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);

            return [
                'creditorA' => Ledger::create(['name' => 'Apex Holdings', 'group_id' => $gid('Sundry Creditors'), 'linked_company_id' => $this->a]),
                'outsideCust' => Ledger::create(['name' => 'Walk-in Customer', 'group_id' => $gid('Sundry Debtors')]),
                'sales' => Ledger::create(['name' => 'Sales A/c', 'group_id' => $gid('Sales Accounts')]),
                'purchase' => Ledger::create(['name' => 'Purchase A/c', 'group_id' => $gid('Purchase Accounts')]),
                'widget' => StockItem::create(['name' => 'Widget', 'unit_id' => $unit->id, 'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0]),
                'main' => Godown::where('name', 'Main Location')->value('id'),
            ];
        });

        $post = fn (int $companyId, array $payload) => ActiveCompany::runAs($companyId, fn () => (new VoucherScreen())->post($payload));
        $inv = function (string $type, string $date, Ledger $party, Ledger $rev, StockItem $item, float $qty, float $rate, ?int $godownId, ?int $counterparty = null) {
            $base = round($qty * $rate, 2);
            $pSide = $type === 'sales' ? 'Dr' : 'Cr';
            $lSide = $type === 'sales' ? 'Cr' : 'Dr';
            $p = ['type' => $type, 'date' => $date, 'party_ledger_id' => $party->id, 'lines' => [
                ['ledger_id' => $party->id, 'dr_cr' => $pSide, 'amount' => $base],
                ['ledger_id' => $rev->id, 'dr_cr' => $lSide, 'amount' => $base],
            ], 'items' => [['stock_item_id' => $item->id, 'godown_id' => $godownId, 'qty' => $qty, 'rate' => $rate]]];
            if ($counterparty) {
                $p['intercompany'] = ['counterparty_company_id' => $counterparty];
            }

            return $p;
        };
        $aMain = ActiveCompany::runAs($this->a, fn () => Godown::where('name', 'Main Location')->value('id'));

        // ═══ 0. An UNTAGGED inter-group voucher (posted BEFORE the group exists) ═
        $this->section('Pre-group untagged voucher (the "unaccounted" case)');
        $untagged = $post($this->a, ['type' => 'payment', 'date' => '2026-06-01', 'lines' => [
            ['ledger_id' => $aDebtorB->id, 'dr_cr' => 'Dr', 'amount' => 100],
            ['ledger_id' => ActiveCompany::runAs($this->a, fn () => Ledger::where('name', 'Cash')->value('id')), 'dr_cr' => 'Cr', 'amount' => 100],
        ]]);
        $this->expect('Untagged voucher posted while ungrouped (12B inert)', $untagged['voucher']['number'] > 0, true);

        $group = CompanyGroup::create(['name' => 'Apex Group', 'slug' => 'apex-group']);
        $group->companies()->attach($this->a);
        $group->companies()->attach($this->b);

        // ═══ 1. THE CANONICAL SCENARIO ═══════════════════════════════════════════
        $this->section('Canonical scenario: buy 100@100 → sell 100@120 to B → B sells 30@150');
        $post($this->a, $inv('purchase', '2026-06-02', $aSupplier, $aPurchase, $aWidget, 100, 100.0, $aMain));
        $post($this->a, $inv('sales', '2026-06-05', $aDebtorB, $aSales, $aWidget, 100, 120.0, $aMain, $this->b));
        $post($this->b, $inv('purchase', '2026-06-06', $bL['creditorA'], $bL['purchase'], $bL['widget'], 100, 120.0, $bL['main'], $this->a));
        $post($this->b, $inv('sales', '2026-06-10', $bL['outsideCust'], $bL['sales'], $bL['widget'], 30, 150.0, $bL['main']));

        // ═══ 2. GROUP TRIAL BALANCE ══════════════════════════════════════════════
        $this->section('Group Trial Balance');
        $tb = $svc()->groupTrialBalance($group, $from, $to);
        $this->expect('Σ Dr = Σ Cr after eliminations', $tb['balanced'], true);
        $this->expect('Inter-company balance symmetric (linked-ledger imbalance 0)', $tb['eliminations']['mismatch'], 0);
        $this->expect('Both tagged vouchers eliminated', $tb['eliminations']['voucher_count'], 2);

        // ═══ 3. GROUP P&L — the exact checklist numbers ══════════════════════════
        $this->section('Group P&L — complete + unrealised elimination');
        $pl = $svc()->groupProfitAndLoss($group, $from, $to);
        $this->expect('Group sales revenue ₹4,500 (A\'s ₹12,000 eliminated)', $pl['trading_income'], 450000);
        $this->expect('Group purchases ₹10,000 (B\'s ₹12,000 eliminated)', $pl['trading_expense'], 1000000);
        $this->expect('Unrealised elimination = 70 × ₹20 = ₹1,400', $pl['unrealised']['total'], 140000);
        $this->expect('Group closing stock ₹7,000 (not B\'s ₹8,400)', $pl['closing_stock'], 700000);
        $this->expect('Group net profit ₹1,500', $pl['net'], 150000);
        $sumNets = (int) array_sum(array_column($pl['member_nets'], 'net'));
        $this->expect('Σ individual nets = ₹2,900 (A ₹2,000 + B ₹900)', $sumNets, 290000);
        $this->expect('TIE CHECK: net = ΣNets − unrealised (2,900 − 1,400 = 1,500)', $pl['tie_check'], true);

        // ═══ 4. GROUP BALANCE SHEET ══════════════════════════════════════════════
        $this->section('Group Balance Sheet');
        $bs = $svc()->groupBalanceSheet($group, $from, $to);
        $this->expect('BS balanced (difference 0)', [$bs['balanced'], $bs['difference']], [true, 0]);
        $this->expect('BS net profit ties to the P&L net', $bs['net'], $pl['net']);
        // Assets: A cash −100 + A residual receivable 100 + B outside receivable 4,500 + stock 7,000
        $this->expect('Asset side ₹11,500', $bs['asset_total'], 1150000);
        $this->expect('Liability side ₹11,500 (outside payable 10,000 + net 1,500)', $bs['liability_total'], 1150000);
        // The ₹12,000 receivable/payable pair vanished; outside balances intact.
        $debtors = collect($bs['asset_sections'])->firstWhere('name', 'Sundry Debtors');
        $this->expect('Sundry Debtors after elimination = ₹4,600 (outside 4,500 + untagged residue 100)', $debtors['closing'] ?? null, 460000);
        $creditors = collect($bs['liability_sections'])->firstWhere('name', 'Sundry Creditors');
        $this->expect('Sundry Creditors after elimination = ₹10,000 (outside only)', -($creditors['closing'] ?? 0), 1000000);

        // ═══ 5. THE ADJUSTMENTS PANEL (audit trail) ══════════════════════════════
        $this->section('Adjustments panel');
        $panel = $svc()->adjustmentsPanel($group, $from, $to);
        $this->expect('Panel: inter-company sales eliminated ₹12,000', $panel['complete']['categories']['sales'], 1200000);
        $this->expect('Panel: purchases eliminated ₹12,000', $panel['complete']['categories']['purchases'], 1200000);
        $this->expect('Panel: receivables eliminated ₹12,000', $panel['complete']['categories']['receivables'], 1200000);
        $this->expect('Panel: payables eliminated ₹12,000', $panel['complete']['categories']['payables'], 1200000);
        $this->expect('Panel: unrealised total ₹1,400 on 1 item line', [$panel['unrealised']['total'], count($panel['unrealised']['items'])], [140000, 1]);
        $this->expect('Panel: the pre-group voucher surfaces as UNACCOUNTED', count($panel['untagged']), 1);
        $this->expect('…identified precisely', [$panel['untagged'][0]['type'], $panel['untagged'][0]['amount_paise']], ['payment', 10000]);

        // ═══ 6. UNMATCHED LOT — listed, never misvalued ══════════════════════════
        $this->section('Unmatched lot: listed, not eliminated');
        $post($this->a, $inv('purchase', '2026-06-15', $aSupplier, $aPurchase, $aWidget, 10, 100.0, $aMain));
        $post($this->a, $inv('sales', '2026-06-16', $aDebtorB, $aSales, $aWidget, 10, 130.0, $aMain, $this->b));
        $post($this->b, $inv('purchase', '2026-06-17', $bL['creditorA'], $bL['purchase'], $bL['widget'], 10, 130.0, $bL['main'], $this->a));
        // Rig the fresh lot unmatched (the checklist's explicit rig).
        ActiveCompany::runAs($this->b, fn () => StockLot::where('received_rate_paise', 13000)
            ->update(['source_voucher_id' => null, 'source_cost_paise' => null]));

        $pl2 = $svc()->groupProfitAndLoss($group, $from, $to);
        $this->expect('Matched elimination UNCHANGED at ₹1,400 (unmatched excluded)', $pl2['unrealised']['total'], 140000);
        $this->expect('Unmatched line: 10 units, ₹1,300 at receipt rate', [
            count($pl2['unrealised']['unmatched']),
            $pl2['unrealised']['unmatched'][0]['remaining'],
            $pl2['unrealised']['unmatched'][0]['value_at_receipt'],
        ], [1, 10.0, 130000]);
        $this->expect('Group stock = ₹8,300 (9,700 − matched 1,400; unmatched NOT deducted)', $pl2['closing_stock'], 830000);
        $this->expect('Group net ₹1,800 and still ties (ΣNets 3,200 − matched 1,400)', [$pl2['net'], $pl2['tie_check']], [180000, true]);
        $bs2 = $svc()->groupBalanceSheet($group, $from, $to);
        $this->expect('BS still balanced with the unmatched lot surfaced', [$bs2['balanced'], $bs2['asset_total']], [true, 1280000]);

        // ═══ 6b. ONE-SIDED settlement SURFACES (review fix: dead-mismatch → real) ═
        $this->section('One-sided tagged settlement is detected (was silently absorbed)');
        // A pays ₹500 to settle its balance with B and tags it; B never records the
        // receipt. The old dr−cr mismatch was structurally 0 and hid this; the
        // linked-ledger imbalance now catches it (BS-only legs, no P&L, no tie hit).
        $aCash = ActiveCompany::runAs($this->a, fn () => Ledger::where('name', 'Cash')->value('id'));
        $post($this->a, ['type' => 'payment', 'date' => '2026-06-25', 'party_ledger_id' => $aDebtorB->id, 'lines' => [
            ['ledger_id' => $aDebtorB->id, 'dr_cr' => 'Dr', 'amount' => 500],
            ['ledger_id' => $aCash, 'dr_cr' => 'Cr', 'amount' => 500],
        ], 'intercompany' => ['counterparty_company_id' => $this->b]]);
        $elimAfter = $svc()->completeEliminations($group, null, $to);
        $this->expect('Linked-ledger imbalance now NON-ZERO (₹500 one-sided)', $elimAfter['mismatch'], 50000);
        $tbAfter = $svc()->groupTrialBalance($group, $from, $to);
        $this->expect('Group TB still balances (imbalance is surfaced, not injected)', $tbAfter['balanced'], true);
        $panelAfter = $svc()->adjustmentsPanel($group, $from, $to);
        $this->expect('Adjustments panel exposes the asymmetry', $panelAfter['complete']['mismatch'] !== 0, true);
        // Post B's matching receipt → the imbalance closes.
        $bCash = ActiveCompany::runAs($this->b, fn () => Ledger::where('name', 'Cash')->value('id'));
        $post($this->b, ['type' => 'receipt', 'date' => '2026-06-25', 'party_ledger_id' => $bL['creditorA']->id, 'lines' => [
            ['ledger_id' => $bCash, 'dr_cr' => 'Dr', 'amount' => 500],
            ['ledger_id' => $bL['creditorA']->id, 'dr_cr' => 'Cr', 'amount' => 500],
        ], 'intercompany' => ['counterparty_company_id' => $this->a]]);
        $this->expect('Both sides posted → imbalance returns to 0', $svc()->completeEliminations($group, null, $to)['mismatch'], 0);

        // ═══ 7. Read-only + per-company invariants ═══════════════════════════════
        $this->section('Consolidation posted NOTHING; per-company TBs intact');
        foreach ([$this->a => 'A', $this->b => 'B'] as $cid => $label) {
            $tbi = ActiveCompany::runAs($cid, fn () => (new BalanceService())->trialBalance($from, $to));
            $this->expect("[$label] individual TB still balanced", $tbi['balanced'], true);
        }
        $this->expect('No consolidation vouchers exist anywhere', \App\Models\Voucher::withoutGlobalScope('company')
            ->whereNotIn('company_id', [$this->a, $this->b])->count(), 0);

        // ═══ 8. Single-member group ≡ individual reports ═════════════════════════
        $this->section('Single-member group consolidates to the member\'s own numbers');
        $c = app(CompanyProvisioner::class)->create('Chi Solo Co')->id;
        ActiveCompany::runAs($c, function () {
            $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
            $exp = Ledger::create(['name' => 'Rent', 'group_id' => $gid('Indirect Expenses')]);
            (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-06-20', 'lines' => [
                ['ledger_id' => $exp->id, 'dr_cr' => 'Dr', 'amount' => 700],
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 700],
            ]]);
        });
        $solo = CompanyGroup::create(['name' => 'Solo Group', 'slug' => 'solo-group']);
        $solo->companies()->attach($c);
        $soloPl = $svc()->groupProfitAndLoss($solo, $from, $to);
        $cPl = ActiveCompany::runAs($c, fn () => (new BalanceService())->profitAndLoss($from, $to));
        $this->expect('[solo] group net == member net (nothing to eliminate)', $soloPl['net'], $cPl['net']);
        $this->expect('[solo] zero eliminations', [$soloPl['eliminations']['voucher_count'], $soloPl['unrealised']['total']], [0, 0]);
        $soloTb = $svc()->groupTrialBalance($solo, $from, $to);
        $this->expect('[solo] group TB balanced', $soloTb['balanced'], true);

        // ═══ 9. Mixed base currency refused ══════════════════════════════════════
        $this->section('Mixed-base-currency group refused');
        ActiveCompany::runAs($c, function () use ($c) {
            $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_base' => false]);
            Company::whereKey($c)->update(['base_currency_id' => $usd->id]);
        });
        $mixed = CompanyGroup::create(['name' => 'Mixed Group', 'slug' => 'mixed-group']);
        $solo->companies()->detach($c);
        $mixed->companies()->attach($this->a === 1 ? $c : $c); // C (USD) …
        // … cannot join Apex Group (one-group rule), so test against a 2-member mixed group:
        $mixed->delete();
        $group->companies()->detach($this->b);
        $bUsd = ActiveCompany::runAs($this->b, function () use ($c) {
            $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_base' => false]);

            return $usd->id;
        });
        Company::whereKey($this->b)->update(['base_currency_id' => $bUsd]);
        $group->companies()->attach($this->b);
        $err = $svc()->currencyMismatch($group);
        $this->expect('Mixed currencies named in the refusal', str_contains((string) $err, 'INR') && str_contains((string) $err, 'USD'), true);
        $this->expect('Every report surface refuses', isset($svc()->groupTrialBalance($group, $from, $to)['error']), true);
        // restore
        Company::whereKey($this->b)->update(['base_currency_id' => ActiveCompany::runAs($this->b, fn () => Currency::where('code', 'INR')->value('id'))]);
        $this->expect('Restored: consolidation runs again', isset($svc()->groupTrialBalance($group, $from, $to)['error']), false);
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
