<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Models\AccountGroup;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a known set of ledgers + vouchers and asserts the Trial Balance,
 * Profit & Loss and Balance Sheet identities hold. This is the end-to-end
 * numeric proof for Phase 4. Pass --keep to leave the data in place for the
 * browser reports; otherwise it rolls everything back.
 */
class ProveBalanceCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-balance {--keep : keep the seeded scenario in the DB} {--company= : run in this company (slug or id) instead of a fresh throwaway one}';

    protected $description = 'Seed a known scenario and prove TB/P&L/BS balance';

    public function handle(BalanceService $svc): int
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

        // clean any prior test data (non-reserved)
        VoucherEntry::query()->delete();
        Voucher::query()->delete();
        Ledger::where('is_reserved', false)->delete();

        $gid = fn (string $name) => AccountGroup::where('name', $name)->value('id');

        // Cash exists (reserved) — give it a Dr 100000 opening
        $cash = Ledger::where('name', 'Cash')->first();
        $cash->update(['opening_balance' => 100000, 'opening_balance_type' => 'Dr']);

        $capital = Ledger::create(['name' => 'Capital A/c', 'group_id' => $gid('Capital Account'), 'opening_balance' => 100000, 'opening_balance_type' => 'Cr', 'country' => 'India']);
        $furniture = Ledger::create(['name' => 'Furniture', 'group_id' => $gid('Fixed Assets'), 'opening_balance' => 0, 'country' => 'India']);
        $rent = Ledger::create(['name' => 'Rent Paid', 'group_id' => $gid('Indirect Expenses'), 'opening_balance' => 0, 'country' => 'India']);
        $sales = Ledger::create(['name' => 'Sales', 'group_id' => $gid('Sales Accounts'), 'opening_balance' => 0, 'country' => 'India']);

        $mk = function (string $type, string $date, array $lines) {
            $fy = Voucher::fyStartFor(Carbon::parse($date));
            $v = Voucher::create(['type' => $type, 'number' => Voucher::nextNumber($type, $fy), 'fy_start' => $fy, 'date' => $date, 'narration' => 'proof']);
            foreach ($lines as $i => [$lid, $side, $amt]) {
                VoucherEntry::create(['voucher_id' => $v->id, 'ledger_id' => $lid, 'dr_cr' => $side, 'amount' => $amt, 'line_no' => $i + 1]);
            }
        };

        $mk('payment', '2026-07-01', [[$rent->id, 'Dr', 5000], [$cash->id, 'Cr', 5000]]);
        $mk('receipt', '2026-07-02', [[$cash->id, 'Dr', 20000], [$sales->id, 'Cr', 20000]]);
        $mk('journal', '2026-07-03', [[$furniture->id, 'Dr', 15000], [$cash->id, 'Cr', 15000]]);

        [$from, $to] = $svc->withinFy(null, null);

        $tb = $svc->trialBalance($from, $to);
        $pl = $svc->profitAndLoss($from, $to);
        $bs = $svc->balanceSheet($from, $to);

        $this->line('');
        $this->info('Period: '.$from->toDateString().' to '.$to->toDateString());
        $this->line('--- Trial Balance ---');
        $this->line('  Total Dr = '.BalanceService::money($tb['total_dr']).'   Total Cr = '.BalanceService::money($tb['total_cr']).'   balanced='.($tb['balanced'] ? 'YES' : 'NO'));
        $this->line('--- Profit & Loss ---');
        $this->line('  Income = '.BalanceService::money($pl['total_income']).'   Expenses = '.BalanceService::money($pl['total_expenses']).'   Net '.($pl['is_profit'] ? 'Profit' : 'Loss').' = '.BalanceService::money($pl['net']));
        $this->line('--- Balance Sheet ---');
        $this->line('  Assets = '.BalanceService::money($bs['total_assets']).'   Liabilities = '.BalanceService::money($bs['total_liabilities']).'   Net Profit = '.BalanceService::money($bs['net']));
        $this->line('  Liab side total = '.BalanceService::money($bs['liability_total']).'   Asset side total = '.BalanceService::money($bs['asset_total']).'   balanced='.($bs['balanced'] ? 'YES' : 'NO'));
        $this->line('  Difference in opening balances: liab='.BalanceService::money($bs['liability_diff']).' asset='.BalanceService::money($bs['asset_diff']));

        // Assertions
        $ok = true;
        $expect = function (string $label, $actual, $expected) use (&$ok) {
            $pass = $actual === $expected;
            $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$actual.($pass ? '' : ' (expected '.$expected.')'));
            $ok = $ok && $pass;
        };
        $this->line('');
        $this->line('--- Assertions (paise) ---');
        $expect('TB balanced', $tb['balanced'], true);
        $expect('TB Dr total', $tb['total_dr'], 12000000);
        $expect('TB Cr total', $tb['total_cr'], 12000000);
        $expect('P&L net profit', $pl['net'], 1500000);
        $expect('BS total assets', $bs['total_assets'], 11500000);
        $expect('BS total liabilities', $bs['total_liabilities'], 10000000);
        $expect('BS balanced', $bs['balanced'], true);

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
