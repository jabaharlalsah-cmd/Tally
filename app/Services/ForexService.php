<?php

namespace App\Services;

use App\Models\BillAllocation;
use App\Models\CompanyFeature;
use App\Models\Currency;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Support\PlanGate;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative multi-currency engine for ZeroBook (Phase 11).
 *
 * Multi-currency is a FOREX LAYER over the base-currency books. The base amount on every
 * voucher line stays the source of truth for the Dr/Cr balance gate, so the Trial Balance,
 * Balance Sheet and P&L are untouched — they always report in the base currency. This
 * service adds, alongside, the foreign amount + rate per line, and the two things that make
 * exchange-rate movement real accounting: the REALISED gain/loss when a foreign bill is
 * settled at a different rate, and the UNREALISED gain/loss when an open bill is revalued.
 *
 * THE ONE INVARIANT, verified server-side to the paise:
 *
 *     amount (base paise) = round(foreign_amount × exchange_rate × 100)
 *
 * The client is never trusted for the base amount — this service re-derives it. A tampered
 * rate or amount is rejected. This is the same discipline as GstService / TdsService.
 */
class ForexService
{
    private ?int $gainLedgerId = null;

    private ?int $lossLedgerId = null;

    private ?Currency $baseCurrency = null;

    public function __construct(private ExchangeRateService $rates) {}

    // ---- feature + masters ----------------------------------------------------

    /** F11 flag AND the plan-tier gate (multi-currency is an Enterprise feature). */
    public function enabled(): bool
    {
        return (bool) CompanyFeature::current()->multi_currency && PlanGate::allows('multi_currency');
    }

    public function baseCurrency(): ?Currency
    {
        return $this->baseCurrency ??= Currency::base();
    }

    /** round(foreign × rate) in base-currency paise. */
    public function deriveBase(float $foreignAmount, float $rate): int
    {
        return (int) round($foreignAmount * $rate * 100);
    }

    public function gainLedgerId(): ?int
    {
        return $this->gainLedgerId ??= Ledger::where('name', 'Foreign Exchange Gain')->value('id');
    }

    public function lossLedgerId(): ?int
    {
        return $this->lossLedgerId ??= Ledger::where('name', 'Foreign Exchange Loss')->value('id');
    }

    /** The client-side cache: currencies + latest rates, so foreign entry is 0-network. */
    public function bootData(): array
    {
        if (! $this->enabled()) {
            return ['forexEnabled' => false, 'currencies' => [], 'latestRates' => (object) [], 'baseCurrencyId' => null,
                'forexGainLedgerId' => null, 'forexLossLedgerId' => null];
        }

        return [
            'forexEnabled' => true,
            'currencies' => Currency::orderBy('code')->get()->map->toCache()->all(),
            'latestRates' => (object) $this->rates->latestRates(),
            // The full history, so the client computes rate-on-voucher-date exactly.
            'rateHistory' => (object) $this->rates->historyCache(),
            'baseCurrencyId' => $this->baseCurrency()?->id,
            'forexGainLedgerId' => $this->gainLedgerId(),
            'forexLossLedgerId' => $this->lossLedgerId(),
        ];
    }

    // ---- rate/currency helpers ------------------------------------------------

    private static function rateKey(float $rate): int
    {
        return (int) round($rate * 1_000_000); // 6dp, exact integer comparison
    }

    private function ledgerCurrencyId(int $ledgerId): ?int
    {
        $v = Ledger::whereKey($ledgerId)->value('currency_id');

        return $v !== null ? (int) $v : null;
    }

    // ---- server authority (the after-hook on the shared posting path) ---------

    /**
     * Recompute and verify the forex layer of a voucher payload. Called from
     * VoucherScreen::validatePayload()'s after() step, exactly like the GST/VAT/bill-wise/
     * cost-centre/TDS hooks. Returns null when clean, a message otherwise.
     */
    public function verifyPayload(array $payload): ?string
    {
        $lines = $payload['lines'] ?? [];
        $settlements = $payload['forex_settlement'] ?? [];
        $globalOverride = ! empty($payload['rate_override']);

        // A line "uses forex" if it declares a currency OR its ledger is a foreign ledger.
        $touchesForex = ! empty($settlements);
        foreach ($lines as $line) {
            if (! empty($line['currency_id']) || $this->ledgerCurrencyId((int) ($line['ledger_id'] ?? 0)) !== null) {
                $touchesForex = true;
                break;
            }
        }

        if (! $this->enabled()) {
            return $touchesForex
                ? 'Multi-currency is not enabled for this company (F11), so no foreign-currency line can be posted.'
                : null;
        }
        if (! $touchesForex) {
            return null; // an ordinary base-currency voucher — nothing to check
        }

        $date = Carbon::parse($payload['date']);
        // A period-end revaluation adjusts a foreign ledger's INR carrying value only (the
        // foreign outstanding is unchanged), so its party lines are legitimately base-only.
        $isRevaluation = ! empty($payload['forex_revaluation']);

        // ---- 1. per-line foreign verification -------------------------------
        foreach ($lines as $line) {
            $ledgerId = (int) ($line['ledger_id'] ?? 0);
            $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';
            $ledgerCur = $this->ledgerCurrencyId($ledgerId);
            $lineCur = ! empty($line['currency_id']) ? (int) $line['currency_id'] : null;

            // A foreign-currency ledger MUST carry its own currency on the line — except on
            // a revaluation, where a base-only INR adjustment against the party is correct.
            if ($ledgerCur !== null) {
                if ($lineCur === null) {
                    if ($isRevaluation) {
                        continue; // base-only revaluation adjustment — allowed
                    }
                    return "“{$name}” is a foreign-currency ledger — this line needs a foreign amount and rate.";
                }
                if ($lineCur !== $ledgerCur) {
                    return "“{$name}” must be posted in its own currency.";
                }
            }
            if ($lineCur === null) {
                continue; // a base-currency line
            }

            $currency = Currency::find($lineCur);
            if (! $currency) {
                return 'Unknown currency on a voucher line.';
            }
            if ($currency->is_base) {
                return 'A base-currency line must not carry a foreign amount.';
            }

            $foreign = (float) ($line['foreign_amount'] ?? 0);
            $rate = (float) ($line['exchange_rate'] ?? 0);
            if ($foreign <= 0 || $rate <= 0) {
                return "The foreign amount and rate on “{$name}” must be greater than zero.";
            }

            // THE INVARIANT.
            $expected = $this->deriveBase($foreign, $rate);
            $posted = (int) round(((float) ($line['amount'] ?? 0)) * 100);
            if ($expected !== $posted) {
                return sprintf(
                    'Base amount on “%s” (%s) must equal foreign × rate (%s × %s = %s).',
                    $name, number_format($posted / 100, 2), number_format($foreign, 4),
                    number_format($rate, 6), number_format($expected / 100, 2),
                );
            }

            // Rate validation. A SETTLEMENT line (closing a foreign bill via Against-Ref)
            // legitimately uses the BILL's booked rate; every other foreign line uses the
            // rate on the voucher date, unless an explicit override carries a reason.
            $bookedRate = $this->settlementBookedRate($line, $ledgerId);
            if ($bookedRate !== null) {
                if (self::rateKey($rate) !== self::rateKey($bookedRate)) {
                    return sprintf('“%s” settles a bill booked at %s — the line must close it at that rate, not %s.',
                        $name, number_format($bookedRate, 6), number_format($rate, 6));
                }
                continue; // settlement line verified
            }

            $lineOverride = $globalOverride || ! empty($line['rate_override']);
            $histRate = $this->rates->rateOrNull($lineCur, $date);
            if ($histRate === null) {
                return sprintf('No exchange rate for %s on or before %s — add one in the Currency master before posting.',
                    $currency->code, $date->format('d-M-Y'));
            }
            if (self::rateKey($rate) !== self::rateKey($histRate) && ! $lineOverride) {
                return sprintf(
                    'Rate %s for %s differs from the recorded rate %s on %s. Tick “rate override” (with a reason) to post a contract rate.',
                    number_format($rate, 6), $currency->code, number_format($histRate, 6), $date->format('d-M-Y'),
                );
            }
        }

        // ---- 2. realised gain/loss on settlement ----------------------------
        foreach ($settlements as $s) {
            $err = $this->verifySettlement($payload, $s, $date, $globalOverride);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * Verify one declared settlement: recompute the realised gain/loss from the bill's
     * booked rate and the settlement rate, then confirm the posted forex line matches.
     */
    private function verifySettlement(array $payload, array $s, Carbon $date, bool $globalOverride): ?string
    {
        $ledgerId = (int) ($s['ledger_id'] ?? 0);
        $refName = trim((string) ($s['ref_name'] ?? ''));
        $foreign = (float) ($s['foreign_amount'] ?? 0);
        $settlementRate = (float) ($s['settlement_rate'] ?? 0);
        $glLedgerId = ! empty($s['gain_loss_ledger_id']) ? (int) $s['gain_loss_ledger_id'] : null;
        $name = Ledger::whereKey($ledgerId)->value('name') ?? 'ledger';

        if ($refName === '' || $foreign <= 0 || $settlementRate <= 0) {
            return 'A forex settlement needs a bill reference, a foreign amount and a settlement rate.';
        }

        $gl = $this->computeRealisedGainLoss($ledgerId, $refName, $foreign, $settlementRate);
        if ($gl === null) {
            return "No open foreign-currency bill “{$refName}” on “{$name}” to settle.";
        }

        // The settlement rate must be the market rate on the voucher date (or an override).
        $curId = $this->ledgerCurrencyId($ledgerId);
        $histRate = $curId ? $this->rates->rateOrNull($curId, $date) : null;
        if ($histRate !== null && self::rateKey($settlementRate) !== self::rateKey($histRate) && ! $globalOverride) {
            return sprintf('Settlement rate %s differs from the recorded rate %s on %s. Tick “rate override” to use a contract rate.',
                number_format($settlementRate, 6), number_format($histRate, 6), $date->format('d-M-Y'));
        }

        $realised = $gl['realised_paise'];
        if ($realised === 0) {
            return null; // no gain/loss — no forex line required
        }

        // A gain credits Foreign Exchange Gain; a loss debits Foreign Exchange Loss.
        $isGain = $realised > 0;
        $expectedLedger = $isGain ? $this->gainLedgerId() : $this->lossLedgerId();
        $expectedSide = $isGain ? 'Cr' : 'Dr';
        $mag = abs($realised);

        if ($glLedgerId !== null && $glLedgerId !== $expectedLedger) {
            return sprintf('A forex %s must post to “Foreign Exchange %s”.', $isGain ? 'gain' : 'loss', $isGain ? 'Gain' : 'Loss');
        }

        // Confirm exactly the expected forex line is present among the posted lines.
        $found = 0;
        foreach ($payload['lines'] ?? [] as $line) {
            if ((int) ($line['ledger_id'] ?? 0) === $expectedLedger && ($line['dr_cr'] ?? null) === $expectedSide) {
                $found += (int) round(((float) ($line['amount'] ?? 0)) * 100);
            }
        }
        if ($found !== $mag) {
            return sprintf(
                'Realised forex %s on settling “%s” should be %s to Foreign Exchange %s — the voucher posts %s.',
                $isGain ? 'gain' : 'loss', $refName, number_format($mag / 100, 2), $isGain ? 'Gain' : 'Loss', number_format($found / 100, 2),
            );
        }

        return null;
    }

    /**
     * If a line closes an existing foreign bill via an Against-Ref allocation, return that
     * bill's booked rate — the rate the line must use (closing at book value). Otherwise null.
     */
    private function settlementBookedRate(array $line, int $ledgerId): ?float
    {
        foreach ($line['allocations'] ?? [] as $a) {
            if (($a['ref_type'] ?? null) === 'against') {
                $rate = $this->bookedRateForBill($ledgerId, trim((string) ($a['ref_name'] ?? '')));
                if ($rate !== null) {
                    return $rate;
                }
            }
        }

        return null;
    }

    /** The rate a foreign bill (ledger, ref_name) was originally booked at (its New-Ref entry). */
    public function bookedRateForBill(int $ledgerId, string $refName): ?float
    {
        $rate = BillAllocation::query()
            ->join('voucher_entries', 'voucher_entries.id', '=', 'bill_allocations.voucher_entry_id')
            ->where('bill_allocations.ledger_id', $ledgerId)
            ->where('bill_allocations.ref_name', $refName)
            ->whereIn('bill_allocations.ref_type', ['new', 'advance'])
            ->whereNotNull('voucher_entries.exchange_rate')
            ->orderBy('bill_allocations.id')
            ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'voucher_entries.voucher_id'))
            ->value('voucher_entries.exchange_rate');

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * The realised gain/loss on settling a foreign bill. Given the bill and this
     * settlement's foreign amount + rate:
     *
     *   settlement_cash = round(foreign × settlement_rate)      // base actually moved
     *   booked_value    = round(foreign × booked_rate)          // base being closed
     *   realised        = receivable ? (cash − booked) : (booked − cash)
     *
     *   realised > 0 → GAIN  (Cr Foreign Exchange Gain)
     *   realised < 0 → LOSS  (Dr Foreign Exchange Loss)
     *
     * @return ?array{realised_paise:int, is_gain:bool, is_receivable:bool, booked_value_paise:int, settlement_cash_paise:int, booked_rate:float}
     */
    public function computeRealisedGainLoss(int $ledgerId, string $refName, float $settlementForeign, float $settlementRate): ?array
    {
        $bookedRate = $this->bookedRateForBill($ledgerId, $refName);
        if ($bookedRate === null) {
            return null;
        }
        $pending = $this->billPendingPaise($ledgerId, $refName);
        if ($pending === 0) {
            return null;
        }
        $isReceivable = $pending > 0; // Dr-terms: a receivable is a debit balance

        $settlementCash = $this->deriveBase($settlementForeign, $settlementRate);
        $bookedValue = $this->deriveBase($settlementForeign, $bookedRate);
        $realised = $isReceivable ? ($settlementCash - $bookedValue) : ($bookedValue - $settlementCash);

        return [
            'realised_paise' => $realised,
            'is_gain' => $realised > 0,
            'is_receivable' => $isReceivable,
            'booked_value_paise' => $bookedValue,
            'settlement_cash_paise' => $settlementCash,
            'booked_rate' => $bookedRate,
        ];
    }

    /** A bill's pending in Dr-terms paise (Dr = +, Cr = −), mirroring BillService. */
    private function billPendingPaise(int $ledgerId, string $refName): int
    {
        $rows = BillAllocation::query()
            ->join('voucher_entries', 'voucher_entries.id', '=', 'bill_allocations.voucher_entry_id')
            ->where('bill_allocations.ledger_id', $ledgerId)
            ->where('bill_allocations.ref_name', $refName)
            ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'voucher_entries.voucher_id'))
            ->get(['bill_allocations.amount', 'voucher_entries.dr_cr']);

        $pending = 0;
        foreach ($rows as $r) {
            $paise = (int) round(((float) $r->amount) * 100);
            $pending += $r->dr_cr === 'Cr' ? -$paise : $paise;
        }

        return $pending;
    }

    // ---- unrealised gain/loss (period-end revaluation) ------------------------

    /**
     * The revaluation report data as of a date. For each OPEN foreign-currency bill:
     * its remaining foreign amount, the base it is currently booked at, what it would be
     * worth at today's rate, and the unrealised gain/loss (computed, never auto-posted).
     *
     * remaining_foreign = |pending_base| / booked_rate
     * revalued_base     = round(remaining_foreign × current_rate)
     * unrealised        = receivable ? (revalued − booked) : (booked − revalued)
     */
    public function revaluation(Carbon $asOf): array
    {
        $foreignLedgers = Ledger::whereNotNull('currency_id')->with('currency')->orderBy('name')->get();
        $bill = app(BillService::class);

        $byCurrency = [];
        $totalUnrealised = 0;
        $missingRates = [];

        foreach ($foreignLedgers as $ledger) {
            $currency = $ledger->currency;
            if (! $currency || $currency->is_base) {
                continue;
            }
            $currentRate = $this->rates->rateOrNull($currency->id, $asOf);
            $bills = $bill->bills([$ledger->id], $asOf);

            foreach ($bills as $b) {
                if ($b['pending'] === 0) {
                    continue; // closed
                }
                $bookedRate = $this->bookedRateForBill($ledger->id, $b['ref_name']);
                if ($bookedRate === null || $bookedRate <= 0) {
                    continue; // not a foreign bill
                }
                $isReceivable = $b['pending'] > 0;
                $bookedBase = abs($b['pending']);
                $remainingForeign = $bookedBase / 100 / $bookedRate; // magnitude, in foreign units

                if ($currentRate === null) {
                    $missingRates[$currency->code] = true;
                    continue;
                }
                $revaluedBase = (int) round($remainingForeign * $currentRate * 100);
                $unrealised = $isReceivable ? ($revaluedBase - $bookedBase) : ($bookedBase - $revaluedBase);

                $byCurrency[$currency->code] ??= [
                    'currency_id' => $currency->id, 'code' => $currency->code, 'symbol' => $currency->symbol,
                    'current_rate' => $currentRate, 'rows' => [], 'unrealised_paise' => 0,
                ];
                $byCurrency[$currency->code]['rows'][] = [
                    'ledger_id' => $ledger->id,
                    'party' => $ledger->name,
                    'ref_name' => $b['ref_name'],
                    'foreign' => round($remainingForeign, 4),
                    'foreign_label' => $currency->code.' '.number_format($remainingForeign, 2),
                    'booked_rate' => $bookedRate,
                    'booked' => BalanceService::money($bookedBase),
                    'booked_paise' => $bookedBase,
                    'revalued' => BalanceService::money($revaluedBase),
                    'revalued_paise' => $revaluedBase,
                    'unrealised' => BalanceService::money(abs($unrealised)),
                    'unrealised_signed' => $unrealised,
                    'is_gain' => $unrealised > 0,
                    'is_receivable' => $isReceivable,
                ];
                $byCurrency[$currency->code]['unrealised_paise'] += $unrealised;
                $totalUnrealised += $unrealised;
            }
        }

        return [
            'currencies' => array_values($byCurrency),
            'total_unrealised' => BalanceService::money(abs($totalUnrealised)),
            'total_unrealised_signed' => $totalUnrealised,
            'is_net_gain' => $totalUnrealised > 0,
            'missing_rates' => array_keys($missingRates),
            'as_of' => $asOf->format('d-M-Y'),
            'as_of_date' => $asOf->toDateString(),
        ];
    }

    /**
     * The balanced Journal a user posts (after review) to book the unrealised gain/loss.
     * Each open foreign bill contributes one party leg + one forex leg; legs are aggregated
     * by ledger. It posts through VoucherScreen::post() like any Journal — the balance gate
     * applies. Base-currency lines: the reval adjusts the INR carrying value only (the
     * foreign outstanding is unchanged), so it surfaces as "on account" in Outstandings.
     *
     * @return array{lines:array, has_data:bool, total:string}
     */
    public function revaluationJournalLines(Carbon $asOf): array
    {
        $reval = $this->revaluation($asOf);
        $partyDr = [];  // ledger_id => paise (magnitude on Dr)
        $partyCr = [];
        $gainPaise = 0;
        $lossPaise = 0;

        foreach ($reval['currencies'] as $c) {
            foreach ($c['rows'] as $r) {
                $u = $r['unrealised_signed'];
                if ($u === 0) {
                    continue;
                }
                if ($u > 0) {
                    // gain: the party's base value moves up (asset ↑ / liability ↓ = Dr party), Cr Gain
                    $partyDr[$r['ledger_id']] = ($partyDr[$r['ledger_id']] ?? 0) + $u;
                    $gainPaise += $u;
                } else {
                    // loss: Cr party, Dr Loss
                    $partyCr[$r['ledger_id']] = ($partyCr[$r['ledger_id']] ?? 0) + abs($u);
                    $lossPaise += abs($u);
                }
            }
        }

        // A bill-wise party ledger needs its adjustment allocated; the reval isn't tied to
        // one bill, so it rides an ON-ACCOUNT reference (an unbilled adjustment) — which is
        // exactly how the Outstandings reconciliation already treats a non-bill amount.
        $ref = 'Forex Reval '.$asOf->format('d-M-Y');
        $bill = app(BillService::class);
        $partyLine = function (int $lid, string $side, int $paise) use ($bill, $ref) {
            $amount = round($paise / 100, 2);
            $line = ['ledger_id' => $lid, 'dr_cr' => $side, 'amount' => $amount];
            if ($bill->enabled() && $bill->isBillWise($lid)) {
                $line['allocations'] = [['ref_type' => 'onaccount', 'ref_name' => $ref, 'amount' => $amount, 'due_date' => null]];
            }

            return $line;
        };

        $lines = [];
        foreach ($partyDr as $lid => $p) {
            $lines[] = $partyLine($lid, 'Dr', $p);
        }
        foreach ($partyCr as $lid => $p) {
            $lines[] = $partyLine($lid, 'Cr', $p);
        }
        if ($gainPaise > 0) {
            $lines[] = ['ledger_id' => $this->gainLedgerId(), 'dr_cr' => 'Cr', 'amount' => round($gainPaise / 100, 2)];
        }
        if ($lossPaise > 0) {
            $lines[] = ['ledger_id' => $this->lossLedgerId(), 'dr_cr' => 'Dr', 'amount' => round($lossPaise / 100, 2)];
        }

        return [
            'lines' => $lines,
            'has_data' => ! empty($lines),
            'total' => BalanceService::money($gainPaise + $lossPaise),
        ];
    }
}
