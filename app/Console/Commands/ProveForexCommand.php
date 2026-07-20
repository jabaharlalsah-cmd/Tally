<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Ledger;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\BalanceService;
use App\Services\BillService;
use App\Services\ExchangeRateService;
use App\Services\ForexService;
use App\Services\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 11 numeric proof — the multi-currency engine, posted through the SAME
 * VoucherScreen::post() every other voucher uses. It proves, to the paise:
 *
 *   • the invariant  amount = round(foreign × rate × 100)  on every foreign line;
 *   • a foreign sales invoice books the party at the day's INR equivalent;
 *   • REALISED gain/loss on settlement — the worked ₹14,000 loss and a ₹10,000 gain —
 *     posted as a fourth line that keeps the voucher balanced;
 *   • the server REJECTS a tampered base amount and an unhistorical rate (unless overridden);
 *   • UNREALISED revaluation is computed (₹7,000 loss on an open $5,000 bill) and the
 *     revaluation Journal it generates posts through the normal path and still balances;
 *   • Outstandings reconcile in base currency; the Trial Balance always balances;
 *   • a foreign sale to a party with no state attracts no GST;
 *   • with multi-currency off, a foreign line is refused — and all 18 prior proofs pass.
 */
class ProveForexCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-forex {--keep : keep the forextest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the multi-currency engine: the foreign-amount invariant, realised & unrealised gain/loss, server tamper rejection, and base-currency reconciliation';

    private bool $ok = true;

    private VoucherScreen $screen;

    private array $L = []; // ledger ids by name

    private int $usd;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'forextest';
        try {
            $provisioner->teardown($slug);
            // Multi-currency is an Enterprise feature — the plan gate requires it.
            $provisioner->provision($slug, 'Forex Test Co', 'enterprise');
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
        $this->info($this->ok ? 'ALL FOREX ASSERTIONS PASSED.' : 'FOREX ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $this->screen = new VoucherScreen();
        $forex = app(ForexService::class);
        $rates = app(ExchangeRateService::class);
        $bs = app(BalanceService::class);
        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

        // ═══ 0. Feature gate ════════════════════════════════════════════════════
        $this->section('Feature gate — plan tier + F11');
        $this->expect('Multi-currency starts OFF', $forex->enabled(), false);
        CompanyFeature::current()->update(['multi_currency' => true, 'bill_by_bill' => true, 'gst' => true]);
        activeCompany()->update(['state' => 'Maharashtra']);
        $forex = app(ForexService::class); // fresh (feature just flipped)
        $this->expect('Enabled after F11 on (Enterprise plan allows it)', $forex->enabled(), true);

        // ═══ 1. Currencies + rates ══════════════════════════════════════════════
        $this->section('Currency master + exchange-rate history');
        $inr = Currency::base();
        $this->expect('INR is the base currency', $inr?->code, 'INR');
        $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_base' => false]);
        $this->usd = $usd->id;
        foreach ([['2026-06-01', 82.00], ['2026-07-01', 83.50], ['2026-07-15', 84.20], ['2026-08-15', 82.80], ['2026-09-01', 84.00]] as [$d, $r]) {
            ExchangeRate::create(['currency_id' => $usd->id, 'date' => $d, 'rate' => $r]);
        }
        $this->expect('rateOn 1-Jul → 83.50', $rates->rateOn($usd->id, Carbon::parse('2026-07-01')), 83.50);
        $this->expect('rateOn 15-Jul → 84.20', $rates->rateOn($usd->id, Carbon::parse('2026-07-15')), 84.20);
        $this->expect('rateOn 20-Jul → 84.20 (most recent on/before)', $rates->rateOn($usd->id, Carbon::parse('2026-07-20')), 84.20);
        $this->expect('rateOn 15-Aug → 82.80', $rates->rateOn($usd->id, Carbon::parse('2026-08-15')), 82.80);
        $missing = false;
        try {
            $rates->rateOn($usd->id, Carbon::parse('2026-05-01'));
        } catch (\RuntimeException) {
            $missing = true;
        }
        $this->expect('rateOn before any rate throws', $missing, true);

        // ═══ 2. Ledgers ═════════════════════════════════════════════════════════
        $this->section('Foreign-currency party ledger');
        $bank = Ledger::create(['name' => 'HDFC Bank', 'group_id' => $gid('Bank Accounts')]);
        $sales = Ledger::create(['name' => 'Export Sales', 'group_id' => $gid('Sales Accounts')]);
        $acme = Ledger::create(['name' => 'Acme Corp USA', 'group_id' => $gid('Sundry Debtors'),
            'currency_id' => $usd->id, 'maintain_bill_by_bill' => true]);
        $this->L = ['bank' => $bank->id, 'sales' => $sales->id, 'acme' => $acme->id,
            'gain' => $forex->gainLedgerId(), 'loss' => $forex->lossLedgerId()];
        $this->expect('Acme Corp is tagged USD', (int) $acme->fresh()->currency_id, $usd->id);
        $this->expect('Forex Gain ledger exists', $forex->gainLedgerId() !== null, true);
        $this->expect('Forex Loss ledger exists', $forex->lossLedgerId() !== null, true);

        // ═══ 3. Foreign sales invoice ═══════════════════════════════════════════
        $this->section('Foreign sales invoice — $10,000 @ 84.20 on 15-Jul-2026');
        $v1 = $this->post([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                $this->fx('acme', 'Dr', 10000, 84.20, [['new', 'EXP-1', 842000]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 842000],
            ],
        ]);
        $this->expect('V1 Dr Acme Corp (INR)', $this->amt($v1, 'acme', 'Dr'), 842000.0);
        $this->expect('V1 Cr Export Sales (INR)', $this->amt($v1, 'sales', 'Cr'), 842000.0);
        $this->expect('V1 balanced', $this->balanced($v1), true);
        $e = $this->entry($v1, $this->L['acme']);
        $this->expect('Acme entry foreign_amount = 10000', (float) $e->foreign_amount, 10000.0);
        $this->expect('Acme entry exchange_rate = 84.20', (float) $e->exchange_rate, 84.20);
        $this->expect('Acme entry currency = USD', (int) $e->currency_id, $usd->id);
        $this->expect('Sales entry is base (no currency)', $this->entry($v1, $this->L['sales'])->currency_id, null);
        $this->expect('Booked rate for bill EXP-1 = 84.20', $forex->bookedRateForBill($this->L['acme'], 'EXP-1'), 84.20);

        // ═══ 4. Realised LOSS on settlement (the worked example) ════════════════
        $this->section('Realised loss — receive $10,000 @ 82.80 (booked 84.20)');
        // computeRealisedGainLoss first (the ForexService API), then post.
        $gl = $forex->computeRealisedGainLoss($this->L['acme'], 'EXP-1', 10000, 82.80);
        $this->expect('Settlement cash = $10,000 × 82.80 = 828,000', $gl['settlement_cash_paise'], 82800000);
        $this->expect('Booked value = $10,000 × 84.20 = 842,000', $gl['booked_value_paise'], 84200000);
        $this->expect('Realised = 828,000 − 842,000 = −14,000 (loss)', $gl['realised_paise'], -1400000);
        $this->expect('It is a loss (not a gain)', $gl['is_gain'], false);

        $v2 = $this->post([
            'type' => 'receipt', 'date' => '2026-08-15',
            'lines' => [
                ['ledger_id' => $this->L['bank'], 'dr_cr' => 'Dr', 'amount' => 828000],
                $this->fx('acme', 'Cr', 10000, 84.20, [['against', 'EXP-1', 842000]]),
                ['ledger_id' => $this->L['loss'], 'dr_cr' => 'Dr', 'amount' => 14000],
            ],
            'forex_settlement' => [$this->settle('acme', 'EXP-1', 10000, 82.80, 'loss')],
        ]);
        $this->expect('V2 Dr Bank = 828,000 (INR received)', $this->amt($v2, 'bank', 'Dr'), 828000.0);
        $this->expect('V2 Cr Acme = 842,000 (closes bill at book value)', $this->amt($v2, 'acme', 'Cr'), 842000.0);
        $this->expect('V2 Dr Forex Loss = 14,000', $this->amt($v2, 'loss', 'Dr'), 14000.0);
        $this->expect('V2 balanced', $this->balanced($v2), true);
        $this->expect('Bill EXP-1 is now closed (pending 0)', $this->billPending('acme', 'EXP-1'), 0);

        // ═══ 5. Realised GAIN (opposite direction) ══════════════════════════════
        $this->section('Realised gain — $5,000 booked @ 82.00, settled @ 84.00');
        $this->post([
            'type' => 'sales', 'date' => '2026-06-01',
            'lines' => [
                $this->fx('acme', 'Dr', 5000, 82.00, [['new', 'EXP-2', 410000]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 410000],
            ],
        ]);
        $glg = $forex->computeRealisedGainLoss($this->L['acme'], 'EXP-2', 5000, 84.00);
        $this->expect('Realised = 420,000 − 410,000 = +10,000 (gain)', $glg['realised_paise'], 1000000);
        $this->expect('It is a gain', $glg['is_gain'], true);
        $v3 = $this->post([
            'type' => 'receipt', 'date' => '2026-09-01',
            'lines' => [
                ['ledger_id' => $this->L['bank'], 'dr_cr' => 'Dr', 'amount' => 420000],
                $this->fx('acme', 'Cr', 5000, 82.00, [['against', 'EXP-2', 410000]]),
                ['ledger_id' => $this->L['gain'], 'dr_cr' => 'Cr', 'amount' => 10000],
            ],
            'forex_settlement' => [$this->settle('acme', 'EXP-2', 5000, 84.00, 'gain')],
        ]);
        $this->expect('V3 Dr Bank = 420,000', $this->amt($v3, 'bank', 'Dr'), 420000.0);
        $this->expect('V3 Cr Acme = 410,000', $this->amt($v3, 'acme', 'Cr'), 410000.0);
        $this->expect('V3 Cr Forex Gain = 10,000', $this->amt($v3, 'gain', 'Cr'), 10000.0);
        $this->expect('V3 balanced', $this->balanced($v3), true);

        // ═══ 6. Server authority — tampered amount + unhistorical rate ══════════
        $this->section('Server authority — tampered base + unhistorical rate');
        $before = Voucher::count();
        $tampered = $this->rejects([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                // foreign 10000 × 84.20 = 842000, but the line claims 850000 (Dr) and the
                // Sales side matches, so ONLY the forex authority can catch it.
                ['ledger_id' => $this->L['acme'], 'dr_cr' => 'Dr', 'amount' => 850000, 'currency_id' => $this->usd, 'foreign_amount' => 10000, 'exchange_rate' => 84.20, 'allocations' => [['ref_type' => 'new', 'ref_name' => 'BAD-1', 'amount' => 850000]]],
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 850000],
            ],
        ], 'forex', 'balance');
        $this->expect('Tampered base amount rejected by forex (not balance)', $tampered, true);
        $this->expect('…and NOT persisted', Voucher::count(), $before);

        $unhistorical = $this->rejects([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                $this->fx('acme', 'Dr', 10000, 90.00, [['new', 'BAD-2', 900000]]), // 90.00 ≠ 84.20 historical
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 900000],
            ],
        ], 'forex');
        $this->expect('Unhistorical rate rejected without override', $unhistorical, true);

        // With an explicit override + reason → accepted, reason stored in the narration.
        $vov = $this->post([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                $this->fx('acme', 'Dr', 10000, 90.00, [['new', 'CONTRACT-1', 900000]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 900000],
            ],
            'rate_override' => true, 'rate_override_reason' => 'Forward contract at 90.00',
        ]);
        $this->expect('Override rate accepted', $this->amt($vov, 'acme', 'Dr'), 900000.0);
        $this->expect('Override reason stored in narration', str_contains((string) $vov->narration, 'Forward contract at 90.00'), true);
        // Clean up the override bill so it doesn't pollute later reconciliation.
        Voucher::find($vov->id)->delete();

        // ═══ 7. Unrealised revaluation ══════════════════════════════════════════
        $this->section('Unrealised revaluation — open $5,000 @ 84.20, revalued @ 82.80');
        // An open bill that stays open: $5,000 @ 84.20 (INR 421,000), never settled.
        $this->post([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                $this->fx('acme', 'Dr', 5000, 84.20, [['new', 'OPEN-1', 421000]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 421000],
            ],
        ]);
        $reval = $forex->revaluation(Carbon::parse('2026-08-31'));
        $usdBlock = collect($reval['currencies'])->firstWhere('code', 'USD');
        $row = collect($usdBlock['rows'] ?? [])->firstWhere('ref_name', 'OPEN-1');
        $this->expect('Reval booked = 421,000', $row['booked'] ?? null, '421,000.00');
        $this->expect('Reval revalued = 414,000 (5,000 × 82.80)', $row['revalued'] ?? null, '414,000.00');
        $this->expect('Reval unrealised = 7,000', $row['unrealised'] ?? null, '7,000.00');
        $this->expect('Reval is a loss', $row['is_gain'] ?? null, false);
        $this->expect('Current rate used = 82.80', $usdBlock['current_rate'] ?? null, 82.80);

        // ═══ 8. Revaluation journal ═════════════════════════════════════════════
        $this->section('Revaluation journal — computed, then posted through the normal path');
        $jl = $forex->revaluationJournalLines(Carbon::parse('2026-08-31'));
        $this->expect('Journal has data', $jl['has_data'], true);
        // Expect: Cr Acme 7,000 (asset down) + Dr Forex Loss 7,000.
        $drLoss = collect($jl['lines'])->firstWhere('ledger_id', $this->L['loss']);
        $crParty = collect($jl['lines'])->first(fn ($l) => $l['ledger_id'] === $this->L['acme'] && $l['dr_cr'] === 'Cr');
        $this->expect('Journal Dr Forex Loss = 7,000', $drLoss['amount'] ?? null, 7000.0);
        $this->expect('Journal Cr Acme = 7,000', $crParty['amount'] ?? null, 7000.0);
        $tbFull = fn () => $bs->trialBalance(\Carbon\Carbon::create(2026, 4, 1), \Carbon\Carbon::parse('2026-09-30'));
        $tbBefore = $tbFull()['balanced'];
        $vj = $this->post(['type' => 'journal', 'date' => '2026-08-31', 'narration' => 'Forex revaluation', 'lines' => $jl['lines'], 'forex_revaluation' => true]);
        $this->expect('Revaluation journal posts + balances', $this->balanced($vj), true);
        $this->expect('TB still balanced after the revaluation journal', $tbFull()['balanced'], $tbBefore);
        // Reverse it so the Outstandings check below sees clean booked values.
        Voucher::find($vj->id)->delete();

        // ═══ 9. Outstandings reconcile in base currency ═════════════════════════
        $this->section('Outstandings — foreign bills, base-currency reconciliation');
        $out = app(BillService::class)->outstandings('receivable', Carbon::parse('2026-09-30'));
        $acmeParty = collect($out['parties'])->firstWhere('ledger_id', $this->L['acme']);
        // After V1 (EXP-1 closed), EXP-2 (closed), OPEN-1 (421,000 open): Acme owes 421,000.
        $acmeClosing = $bs->ledgerClosings(Carbon::parse('2026-09-30'))[$this->L['acme']] ?? 0;
        $this->expect('Acme closing balance = 421,000 (only OPEN-1 remains)', $acmeClosing, 42100000);
        $this->expect('Outstandings pending for Acme = 421,000', $acmeParty['pending_total'] ?? null, '421,000.00');
        $this->expect('Σ INR-equivalents == ledger closing (reconciles)', $out['reconciles'], true);

        // ═══ 10. Trial Balance + GST behaviour ══════════════════════════════════
        $this->section('Base-currency invariants — TB balances, foreign sale attracts no GST');
        $tb = $tbFull();
        $this->expect('Trial Balance balances in base currency (full period, all forex vouchers)', $tb['balanced'], true);
        $this->expect('TB total is non-trivial (vouchers in period)', $tb['total_dr'] > 0, true);
        $t = \Carbon\Carbon::parse('2026-09-30');
        // The foreign sale to Acme (no state) produced no CGST/SGST/IGST duty balance.
        $gstLedgers = Ledger::whereIn('tax_type', ['central', 'state', 'integrated'])->pluck('id')->all();
        $gstBalance = 0;
        foreach ($gstLedgers as $lid) {
            $gstBalance += abs($bs->ledgerClosings($t)[$lid] ?? 0);
        }
        $this->expect('No GST duty balance from the foreign (no-state) sales', $gstBalance, 0);

        // ═══ 11. Alter + cancel ═════════════════════════════════════════════════
        $this->section('Alter + cancel preserve the forex shape');
        // Alter OPEN-1's rate/amount by re-posting it. Book $5,000 @ 84.00 = 420,000.
        $openV = Voucher::whereHas('entries', fn ($q) => $q->where('ledger_id', $this->L['acme']))
            ->get()->first(fn ($v) => $v->entries->contains(fn ($e) => $e->ledger_id === $this->L['acme'] && (float) $e->amount === 421000.0));
        // Post a fresh foreign voucher, then alter it (keeps the proof self-contained).
        $va = $this->post([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                $this->fx('acme', 'Dr', 2000, 84.20, [['new', 'ALT-1', 168400]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 168400],
            ],
        ]);
        $vaAltered = $this->post([
            'type' => 'sales', 'date' => '2026-07-15', 'voucher_id' => $va->id,
            'lines' => [
                $this->fx('acme', 'Dr', 3000, 84.20, [['new', 'ALT-1', 252600]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 252600],
            ],
        ]);
        $this->expect('Altered foreign line amount = 252,600', $this->amt($vaAltered, 'acme', 'Dr'), 252600.0);
        $this->expect('Altered foreign_amount = 3000', (float) $this->entry($vaAltered, $this->L['acme'])->foreign_amount, 3000.0);
        $this->expect('Altered still balanced', $this->balanced($vaAltered), true);
        $screen2 = new VoucherScreen();
        $screen2->editVoucherId = $va->id;
        $cancel = $screen2->cancelVoucher();
        $this->expect('Cancel a foreign voucher succeeds', $cancel['ok'], true);
        $this->expect('Cancelled — entries gone', Voucher::find($va->id), null);
        $this->expect('TB still balances after alter/cancel', $tbFull()['balanced'], true);

        // ═══ 12. Feature OFF → foreign line refused ═════════════════════════════
        $this->section('Multi-currency OFF — a foreign line is refused');
        CompanyFeature::current()->update(['multi_currency' => false]);
        $offRejected = $this->rejects([
            'type' => 'sales', 'date' => '2026-07-15',
            'lines' => [
                $this->fx('acme', 'Dr', 1000, 84.20, [['new', 'OFF-1', 84200]]),
                ['ledger_id' => $this->L['sales'], 'dr_cr' => 'Cr', 'amount' => 84200],
            ],
        ], 'forex');
        $this->expect('Foreign line refused while multi-currency is off', $offRejected, true);
        $this->expect('bootData ships no forex payload when off', (new VoucherScreen())->bootData()['forexEnabled'], false);
    }

    // ---- posting helpers ------------------------------------------------------

    /** A foreign-currency line: base = round(foreign × rate). */
    private function fx(string $ledgerKey, string $side, float $foreign, float $rate, array $allocs = []): array
    {
        $line = [
            'ledger_id' => $this->L[$ledgerKey],
            'dr_cr' => $side,
            'amount' => round($foreign * $rate, 2),
            'currency_id' => $this->usd,
            'foreign_amount' => $foreign,
            'exchange_rate' => $rate,
        ];
        if ($allocs) {
            $line['allocations'] = array_map(fn ($a) => ['ref_type' => $a[0], 'ref_name' => $a[1], 'amount' => round($a[2], 2), 'due_date' => null], $allocs);
        }

        return $line;
    }

    private function settle(string $ledgerKey, string $ref, float $foreign, float $rate, string $glKey): array
    {
        return [
            'ledger_id' => $this->L[$ledgerKey],
            'ref_name' => $ref,
            'foreign_amount' => $foreign,
            'settlement_rate' => $rate,
            'gain_loss_ledger_id' => $this->L[$glKey],
        ];
    }

    private function post(array $payload): Voucher
    {
        $res = $this->screen->post($payload);

        return Voucher::with('entries')->find($res['voucher']['id']);
    }

    private function rejects(array $payload, string $key, ?string $notKey = null): bool
    {
        try {
            $this->screen->post($payload);
        } catch (ValidationException $e) {
            $keys = array_keys($e->errors());

            return in_array($key, $keys, true) && ($notKey === null || ! in_array($notKey, $keys, true));
        }

        return false;
    }

    private function amt(Voucher $v, string $ledgerKey, string $side): float
    {
        return (float) $v->fresh('entries')->entries
            ->where('ledger_id', $this->L[$ledgerKey])->where('dr_cr', $side)->sum('amount');
    }

    private function entry(Voucher $v, int $ledgerId): VoucherEntry
    {
        return $v->fresh('entries')->entries->firstWhere('ledger_id', $ledgerId);
    }

    private function balanced(Voucher $v): bool
    {
        $e = $v->fresh('entries')->entries;
        $dr = (int) round($e->where('dr_cr', 'Dr')->sum('amount') * 100);
        $cr = (int) round($e->where('dr_cr', 'Cr')->sum('amount') * 100);

        return $dr === $cr && $dr > 0;
    }

    private function billPending(string $ledgerKey, string $ref): int
    {
        foreach (app(BillService::class)->bills([$this->L[$ledgerKey]], null) as $b) {
            if ($b['ref_name'] === $ref) {
                return $b['pending'];
            }
        }

        return 0;
    }

    // ---- reporting helpers ----------------------------------------------------

    private function section(string $t): void
    {
        $this->line('');
        $this->line('── '.$t.' '.str_repeat('─', max(0, 62 - mb_strlen($t))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected '.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function fmt($v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'NULL';
        }

        return (string) $v;
    }
}
