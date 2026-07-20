<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\CostAllocation;
use App\Models\CostCentre;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\CostCentreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5D numeric proof. Posts a cost-allocated voucher through the SAME endpoint
 * the UI uses — VoucherScreen::post() — and asserts:
 *   a line split across two cost centres persists two cost_allocations;
 *   the ACCOUNTING IS UNAFFECTED (the ledger balance and Trial Balance are byte-
 *   identical with or without the cost split);
 *   the allocation = line-amount authority (a mismatched payload is rejected);
 *   the Cost Centre Breakup shows the right per-centre amounts.
 *
 * Rolls back unless --keep.
 */
class ProveCostCentreCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-costcentre {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Post a cost-allocated voucher via the shared path and prove the split + accounting-unaffected + breakup';

    public function handle(CostCentreService $cc, BalanceService $bs): int
    {
        $keep = $this->option('keep');
        DB::beginTransaction();

        // Phase 12A — every proof runs in its OWN fresh company (seeded chart via
        // CompanyProvisioner), created inside this transaction so it rolls back
        // with everything else unless --keep. Re-runs never collide, and each
        // green proof doubles as a per-company isolation check.
        if (! $this->resolveActiveCompany(fresh: empty(trim((string) $this->option('company'))))) {
            DB::rollBack();

            return self::FAILURE;
        }

        CostAllocation::query()->delete();
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        CostCentre::query()->update(['parent_id' => null]); // break the self-FK before a bulk delete
        CostCentre::query()->delete();
        Ledger::where('is_reserved', false)->delete();
        CompanyFeature::current()->update(['cost_centres' => true, 'gst' => false, 'bill_by_bill' => false]);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $rent = Ledger::create(['name' => 'Marketing Spend', 'group_id' => $gid('Indirect Expenses'), 'cost_centres_applicable' => true, 'country' => 'India']);
        $cash = Ledger::where('name', 'Cash')->first();
        $north = CostCentre::create(['name' => 'North Zone']);
        $south = CostCentre::create(['name' => 'South Zone']);

        $screen = new VoucherScreen;

        // Payment: Dr Marketing 6,000 split 4,000 North + 2,000 South / Cr Cash 6,000.
        $r = $screen->post([
            'type' => 'payment', 'date' => '2026-07-04',
            'lines' => [
                ['ledger_id' => $rent->id, 'dr_cr' => 'Dr', 'amount' => 6000, 'cost_allocations' => [
                    ['cost_centre_id' => $north->id, 'amount' => 4000],
                    ['cost_centre_id' => $south->id, 'amount' => 2000],
                ]],
                ['ledger_id' => $cash->id, 'dr_cr' => 'Cr', 'amount' => 6000],
            ],
        ]);

        // Server authority: allocations that don't sum to the line are rejected.
        $rejected = false;
        try {
            $screen->post([
                'type' => 'payment', 'date' => '2026-07-05',
                'lines' => [
                    ['ledger_id' => $rent->id, 'dr_cr' => 'Dr', 'amount' => 1000, 'cost_allocations' => [
                        ['cost_centre_id' => $north->id, 'amount' => 700], // 700 != 1000
                    ]],
                    ['ledger_id' => $cash->id, 'dr_cr' => 'Cr', 'amount' => 1000],
                ],
            ]);
        } catch (ValidationException $e) {
            $rejected = true;
        }

        $allocs = CostAllocation::where('voucher_id', $r['voucher']['id'])->with('costCentre')->get()->keyBy(fn ($a) => $a->costCentre->name);
        $closings = $bs->ledgerClosings(now());
        $tb = $bs->trialBalance(...$bs->withinFy(null, null));
        $breakup = $cc->breakup(...$bs->withinFy(null, null));
        $byCentre = collect($breakup['centres'])->keyBy('name');
        $northDrill = $cc->centreVouchers($north->id, ...$bs->withinFy(null, null));

        $money = fn ($p) => BalanceService::money($p);

        $this->line('');
        $this->info('Cost centres on · '.$r['voucher']['display_number'].'  Dr Marketing 6,000 (4,000 North + 2,000 South) / Cr Cash 6,000');
        $this->line('   cost allocations: North '.($allocs['North Zone']->amount ?? '-').'  South '.($allocs['South Zone']->amount ?? '-'));
        $this->line('   Marketing closing (Dr terms) = '.$money($closings[$rent->id] ?? 0).'  ← accounting unaffected by the cost split');
        $this->line('   Trial Balance: Dr '.$money($tb['total_dr']).' = Cr '.$money($tb['total_cr']).'  balanced='.($tb['balanced'] ? 'YES' : 'NO'));
        $this->line('   Breakup: North '.($byCentre['North Zone']['total'] ?? '-').'  South '.($byCentre['South Zone']['total'] ?? '-').'  grand '.$breakup['grand_total']);

        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true).($pass ? '' : ' (expected '.var_export($expected, true).')'));
            $ok = $ok && $pass;
        };

        $this->line('');
        $this->line('--- Assertions (paise / flags) ---');
        $expect('Two cost allocations persisted', CostAllocation::where('voucher_id', $r['voucher']['id'])->count(), 2);
        $expect('North allocation = 4,000', (int) round(((float) ($allocs['North Zone']->amount ?? 0)) * 100), 400000);
        $expect('South allocation = 2,000', (int) round(((float) ($allocs['South Zone']->amount ?? 0)) * 100), 200000);
        $expect('Allocations linked to the Marketing entry', (int) CostAllocation::where('voucher_id', $r['voucher']['id'])->whereNotNull('voucher_entry_id')->count(), 2);
        // Accounting unaffected: the ledger closing is exactly the posted 6,000, not 6,000 + splits.
        $expect('Marketing closing == 6,000 (unchanged by cost split)', $closings[$rent->id] ?? 0, 600000);
        $expect('Trial Balance balanced', $tb['balanced'], true);
        // Breakup
        $expect('Breakup North = 4,000', (int) round(((float) str_replace(',', '', $byCentre['North Zone']['total'] ?? '0')) * 100), 400000);
        $expect('Breakup South = 2,000', (int) round(((float) str_replace(',', '', $byCentre['South Zone']['total'] ?? '0')) * 100), 200000);
        $expect('Breakup grand = 6,000', $breakup['grand_total_signed'], 600000);
        // Drill
        $expect('North drill finds the voucher', count($northDrill), 1);
        // Server authority
        $expect('Mismatched cost allocation rejected', $rejected, true);

        if ($keep) {
            DB::commit();
            $this->info($ok ? 'ALL ASSERTIONS PASSED — scenario kept in DB.' : 'ASSERTIONS FAILED — scenario kept for inspection.');
        } else {
            DB::rollBack();
            $this->info($ok ? 'ALL ASSERTIONS PASSED — rolled back.' : 'ASSERTIONS FAILED — rolled back.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
