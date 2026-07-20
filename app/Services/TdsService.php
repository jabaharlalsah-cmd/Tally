<?php

namespace App\Services;

use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\TdsDeducteeYtd;
use App\Models\TdsDeduction;
use App\Models\TdsSection;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative TDS engine for ZeroBook (Phase 10A).
 *
 * Everything is computed in INTEGER PAISE, so the numbers are exact — the same
 * discipline GstService and VatService keep. The method that computes a deduction is
 * the same method that RE-VERIFIES a posted voucher, so a client-supplied TDS amount is
 * never trusted (mirrors GstService::verifyInvoicePayload).
 *
 * ─── THE POSTING SHAPE ──────────────────────────────────────────────────────────────
 *
 *     Dr  Expense ledger          the full taxable amount (professional fees, rent, …)
 *   [ Dr  Input GST duty ]        optional, when the bill charged GST
 *     Cr  TDS Payable             the deducted amount — a liability owed to the Revenue
 *     Cr  Bank                    the NET the vendor actually receives
 *
 * Balanced by construction. When nothing is deducted (below threshold) there is simply
 * no TDS Payable line, and the voucher is the ordinary two-line Payment it always was.
 *
 * ─── THE TAXABLE BASE, AND WHY GST IS EXCLUDED ──────────────────────────────────────
 *
 * Under GST law, TDS is deducted on the taxable value EXCLUDING GST whenever GST is
 * shown separately on the invoice. The server does not take the client's word for this.
 * It derives the base itself:
 *
 *     base = Σ (Dr lines whose ledger has tax_type IS NULL)
 *
 * Every GST/VAT duty ledger carries a non-null tax_type, so they fall out of the sum
 * automatically. A payment of ₹1,00,000 fees + ₹18,000 IGST posts Dr 1,18,000 in total,
 * and the TDS base is ₹1,00,000 — not ₹1,18,000. The payload declares its base; the
 * server recomputes it and rejects any mismatch. That single rule IS the GST-base rule.
 *
 * ─── THE THRESHOLD, AND THE CATCH-UP ────────────────────────────────────────────────
 *
 * TDS is not withheld until the year's aggregate to a vendor under a section crosses the
 * statutory threshold. The moment it crosses, tax is due on the ENTIRE aggregate — the
 * earlier below-threshold payments included. One formula produces both behaviours:
 *
 *     deducted_now = round(aggregate × rate) − already_deducted_this_year
 *
 * Pay ₹40,000 (annual threshold ₹50,000) → below → deduct 0, carry paid = 40,000.
 * Pay ₹15,000 → aggregate 55,000 crosses → 55,000 × 10% = 5,500, minus 0 already
 * deducted → withhold ₹5,500 on a ₹15,000 bill. The vendor receives ₹9,500: the ₹4,000
 * that was never withheld on the first bill is caught up here.
 * Pay ₹20,000 → aggregate 75,000 → 7,500 − 5,500 → withhold ₹2,000. No catch-up left.
 *
 * ─── SECTION-SPECIFIC QUIRKS ────────────────────────────────────────────────────────
 *
 * Sections do not all behave alike, so computeDeduction() dispatches on the NORMALISED
 * section code ('393-194J' → '194J') into a named method per quirk, rather than growing
 * a conditional. Rates and thresholds are always read from the tds_sections row:
 *
 *   deduct194C — a single-bill threshold AND an annual one; either crossing triggers.
 *   deduct194I — the threshold aggregates PER CALENDAR MONTH, not per fiscal year.
 *   deduct194Q — tax falls only on the value in EXCESS of the threshold, not the whole.
 *   deduct194J / deductGeneric — the plain annual-aggregate rule above.
 *
 * ─── SECTION 206AA ──────────────────────────────────────────────────────────────────
 *
 * A deductee who supplies no PAN is deducted at 20%, or the section rate, whichever is
 * HIGHER. Never lower — a 0.1% section (194Q) becomes 20% without a PAN.
 */
class TdsService
{
    /** Cached id of the reserved TDS Payable duty ledger. */
    private ?int $payableLedgerId = null;

    /** Cached [ledger_id => tax_type] for every duty ledger (GST, VAT and TDS). */
    private ?array $dutyLedgers = null;

    // ---- feature + masters ---------------------------------------------------

    public function enabled(): bool
    {
        return (bool) CompanyFeature::current()->tds;
    }

    /**
     * The reserved "TDS Payable" ledger (tax_type = 'tds'). Its tax_role is deliberately
     * NULL: GstService::taxLedgerMap() selects on a non-null tax_role, so a NULL role
     * keeps this ledger completely invisible to the GST and VAT engines.
     */
    public function payableLedgerId(): ?int
    {
        if ($this->payableLedgerId !== null) {
            return $this->payableLedgerId;
        }

        return $this->payableLedgerId = Ledger::where('tax_type', 'tds')->value('id');
    }

    /** [ledger_id => tax_type] for every duty ledger. A Dr line on one of these is NOT a TDS base. */
    private function dutyLedgers(): array
    {
        if ($this->dutyLedgers !== null) {
            return $this->dutyLedgers;
        }

        return $this->dutyLedgers = Ledger::whereNotNull('tax_type')
            ->pluck('tax_type', 'id')->map(fn ($t) => (string) $t)->all();
    }

    /** The sections in force for a fiscal year (defaults to the current one). */
    public function sections(?int $fyStart = null): array
    {
        $fyStart ??= Voucher::statutoryFyStartFor(Carbon::today());

        return TdsSection::effectiveFor($fyStart)
            ->orderBy('code')->get()->map->toCache()->all();
    }

    /**
     * The party ledgers tagged as TDS deductees, for the voucher screen's picker.
     * A ledger qualifies once it has ANY deductee tagging — a PAN, a type, or a default
     * section — so a vendor can be set up before its section is decided.
     */
    public function deducteeCache(): array
    {
        return Ledger::query()
            ->where(fn ($q) => $q->whereNotNull('deductee_pan')
                ->orWhereNotNull('deductee_type')
                ->orWhereNotNull('default_tds_section_id'))
            ->orderBy('name')->get()
            ->map(fn (Ledger $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'path' => $l->deductee_pan ? ('PAN '.$l->deductee_pan) : 'No PAN — 20% under 206AA',
                'deductee_pan' => $l->deductee_pan,
                'deductee_type' => $l->deductee_type,
                'default_tds_section_id' => $l->default_tds_section_id,
                'is_reserved' => false,
            ])->all();
    }

    /**
     * The running year-to-date totals, keyed "deducteeId:sectionId".
     */
    public function ytdCache(?int $fyStart = null): array
    {
        $fyStart ??= Voucher::statutoryFyStartFor(Carbon::today());

        $out = [];
        foreach (TdsDeducteeYtd::where('fy_start', $fyStart)->get() as $row) {
            $out[$row->deductee_ledger_id.':'.$row->tds_section_id] = [
                'paid' => (float) $row->paid_amount,
                'deducted' => (float) $row->deducted_amount,
            ];
        }

        return $out;
    }

    /**
     * The fiscal year's TDS payments, grouped by "deducteeId:sectionId" — one entry per
     * payment, carrying its date, base and deduction.
     *
     * The client needs the individual payments, not merely their totals, because two of
     * the rules window differently: 194I aggregates over the payment's CALENDAR MONTH,
     * and 194C asks which prior bills individually exceeded the single-bill threshold.
     * Shipping the rows lets the client reproduce the server's arithmetic EXACTLY, so the
     * figure the user watches while typing is the figure the server will accept — with
     * zero network round-trips. The server still recomputes and re-verifies on accept;
     * this cache is a convenience, never an authority.
     *
     * @return array<string, array<int, array{voucher_id:int,date:string,payment:float,deducted:float}>>
     */
    public function clientLedgerCache(?int $fyStart = null): array
    {
        $fyStart ??= Voucher::statutoryFyStartFor(Carbon::today());

        $rows = TdsDeduction::query()
            ->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->where('tds_deductions.fy_start', $fyStart)
            ->orderBy('vouchers.date')->orderBy('tds_deductions.id')
            ->get([
                'tds_deductions.voucher_id',
                'tds_deductions.deductee_ledger_id',
                'tds_deductions.tds_section_id',
                'tds_deductions.payment_amount',
                'tds_deductions.deducted_amount',
                'vouchers.date as v_date',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $key = $r->deductee_ledger_id.':'.$r->tds_section_id;
            $out[$key][] = [
                'voucher_id' => (int) $r->voucher_id,
                'date' => $r->v_date instanceof Carbon ? $r->v_date->toDateString() : (string) $r->v_date,
                'payment' => (float) $r->payment_amount,
                'deducted' => (float) $r->deducted_amount,
            ];
        }

        return $out;
    }

    // ---- the base -------------------------------------------------------------

    /**
     * The taxable base of a TDS-engaged Payment, derived from the voucher's own lines:
     * every DEBIT whose ledger is not a duty ledger. GST/VAT duty lines carry a non-null
     * tax_type and so are excluded — this is the pre-GST base the law requires.
     *
     * @param  array  $lines  payload lines [{ledger_id, dr_cr, amount}, …]
     */
    public function basePaiseFromLines(array $lines): int
    {
        $duty = $this->dutyLedgers();
        $base = 0;
        foreach ($lines as $line) {
            if (($line['dr_cr'] ?? null) !== 'Dr') {
                continue;
            }
            if (array_key_exists((int) ($line['ledger_id'] ?? 0), $duty)) {
                continue; // a GST / VAT / TDS duty ledger is never part of the base
            }
            $base += (int) round(((float) ($line['amount'] ?? 0)) * 100);
        }

        return $base;
    }

    /** Σ of the Cr lines posted to the TDS Payable ledger, in paise. */
    private function postedTdsPaise(array $lines): int
    {
        $payableId = $this->payableLedgerId();
        if (! $payableId) {
            return 0;
        }
        $sum = 0;
        foreach ($lines as $line) {
            if ((int) ($line['ledger_id'] ?? 0) === $payableId && ($line['dr_cr'] ?? null) === 'Cr') {
                $sum += (int) round(((float) ($line['amount'] ?? 0)) * 100);
            }
        }

        return $sum;
    }

    // ---- the effective rate ---------------------------------------------------

    /**
     * The rate actually applied: the section's rate for this deductee type, then Section
     * 206AA — no PAN means the section's 206AA floor (20% by default, 5% for 194Q), or the
     * section rate if that is already higher. Never lower: a 0.1% section becomes 5%.
     */
    public function effectiveRate(TdsSection $section, ?Ledger $deductee): float
    {
        $rate = $section->rateFor($deductee?->deductee_type);

        if ($deductee === null || trim((string) $deductee->deductee_pan) === '') {
            return max($rate, $section->noPanFloor());
        }

        return $rate;
    }

    // ---- the deduction --------------------------------------------------------

    /**
     * Compute the TDS to withhold on a payment, from the deductee's threshold state.
     *
     * @param  int   $baseAmountPaise   this payment's taxable base (pre-GST), in paise
     * @param  ?int  $excludeVoucherId  on ALTER, the voucher being re-posted: its own
     *                                  contribution is removed from the running state so
     *                                  the deduction is re-derived as though this voucher
     *                                  were the year's latest payment.
     * @return array{0:int,1:string}    [deducted paise, human-readable reason]
     */
    public function computeDeduction(
        int $deducteeLedgerId,
        int $sectionId,
        int $baseAmountPaise,
        int $fyStart,
        ?int $excludeVoucherId = null,
        ?Carbon $paymentDate = null,
    ): array {
        $d = $this->computeDetailed($deducteeLedgerId, $sectionId, $baseAmountPaise, $fyStart, $excludeVoucherId, $paymentDate);

        return [$d['deducted'], $d['reason']];
    }

    /**
     * The full result: what to withhold, why, and the CUMULATIVE base the liability was
     * computed on. That third figure is what tds_deductions.base_amount stores, and each
     * rule defines it differently — the whole aggregate for a catch-up, the excess over
     * the threshold for 194Q, the sum of individually-large bills for 194C — so the rule
     * returns it rather than anyone re-deriving it. It always holds that:
     *
     *     deducted = round(base × rate) − already_deducted_this_window
     *
     * When nothing is deducted there is no liability and no base, so base falls back to
     * this payment's own amount.
     *
     * @return array{deducted:int, reason:string, base:int, rate:float}
     */
    private function computeDetailed(
        int $deducteeLedgerId,
        int $sectionId,
        int $baseAmountPaise,
        int $fyStart,
        ?int $excludeVoucherId = null,
        ?Carbon $paymentDate = null,
    ): array {
        $section = TdsSection::find($sectionId);
        $deductee = Ledger::find($deducteeLedgerId);

        if (! $section || ! $deductee) {
            return ['deducted' => 0, 'reason' => 'Unknown deductee or section — nothing deducted.', 'base' => max(0, $baseAmountPaise), 'rate' => 0.0];
        }
        if ($baseAmountPaise <= 0) {
            return ['deducted' => 0, 'reason' => 'No taxable base — nothing deducted.', 'base' => 0, 'rate' => 0.0];
        }

        $paymentDate ??= Carbon::today();
        $rate = $this->effectiveRate($section, $deductee);
        $noPan = trim((string) $deductee->deductee_pan) === '';

        // Everything a rule needs to look backwards over its own window: the fiscal year
        // for most sections, the calendar month for a monthly-threshold one (194I).
        $ctx = [
            'deductee_id' => $deducteeLedgerId,
            'section_id' => $sectionId,
            'fy_start' => $fyStart,
            'date' => $paymentDate,
            'exclude' => $excludeVoucherId,
            'monthly' => $section->isMonthly(),
        ];

        // The prior state of that window, with this voucher's own contribution removed.
        $prior = $section->isMonthly()
            ? $this->priorStateForMonth($deducteeLedgerId, $sectionId, $paymentDate, $excludeVoucherId)
            : $this->priorStateForYear($deducteeLedgerId, $sectionId, $fyStart, $excludeVoucherId);

        // Section-specific quirks live in named methods, dispatched on the NORMALISED base
        // code, so '194J' and its Income Tax Act 2025 successor '393-194J' behave
        // identically without either code appearing in a condition twice.
        [$deducted, $reason, $base] = match ($section->baseCodeValue()) {
            '194C' => $this->deduct194C($section, $baseAmountPaise, $prior, $rate, $ctx),
            '194I', '194I-A', '194I-B' => $this->deduct194I($section, $baseAmountPaise, $prior, $rate, $ctx),
            '194Q' => $this->deduct194Q($section, $baseAmountPaise, $prior, $rate),
            '194J' => $this->deduct194J($section, $baseAmountPaise, $prior, $rate),
            default => $this->deductGeneric($section, $baseAmountPaise, $prior, $rate, $ctx),
        };

        if ($deducted > 0 && $noPan) {
            $reason .= sprintf(' No PAN on record — deducted at %s under Section 206AA.', self::pct($rate));
        }

        return ['deducted' => $deducted, 'reason' => $reason, 'base' => $base, 'rate' => $rate];
    }

    // ---- section-specific rules ----------------------------------------------

    /**
     * Fees for professional or technical services (194J / 393-194J). The plain rule:
     * nothing until the fiscal year's aggregate crosses the threshold, then tax on the
     * whole aggregate, catching up on everything paid earlier that year.
     */
    private function deduct194J(TdsSection $section, int $basePaise, array $prior, float $rate): array
    {
        return $this->aggregateRule($section, $basePaise, $prior, $rate, 'this year');
    }

    /**
     * Payments to contractors (194C / 393-194C). TWO thresholds that do DIFFERENT things,
     * and conflating them over-deducts:
     *
     *   • A single bill above ₹30,000 is deducted ON THAT BILL, even though the year is
     *     nowhere near ₹1,00,000. Small earlier bills stay untaxed.
     *   • Once the year's aggregate also passes ₹1,00,000, tax falls on the WHOLE
     *     aggregate — the small earlier bills included.
     *
     * So the liability is tax on the qualifying bills while the aggregate is under the
     * annual threshold, and tax on everything once it is over. Subtracting what has
     * already been withheld turns either into the amount to withhold now.
     */
    private function deduct194C(TdsSection $section, int $basePaise, array $prior, float $rate, array $ctx): array
    {
        return $this->singleAndAggregateRule($section, $basePaise, $prior, $rate, $ctx, 'this year');
    }

    /**
     * Rent (194I / 393-194I). The Finance Act 2025 replaced the annual ₹2,40,000 threshold
     * with ₹50,000 PER MONTH (or part of a month) from FY 2025-26, so the window this rule
     * aggregates over is the calendar month of the payment, not the fiscal year. Older
     * 194I rows (threshold_period = 'annual') still aggregate yearly — the ROW's data
     * decides, not this method, which is the whole point of keeping thresholds in a table.
     */
    private function deduct194I(TdsSection $section, int $basePaise, array $prior, float $rate, array $ctx): array
    {
        $window = $section->isMonthly() ? 'in '.$ctx['date']->format('M Y') : 'this year';

        return $this->aggregateRule($section, $basePaise, $prior, $rate, $window);
    }

    /**
     * Purchase of goods (194Q / 393-194Q). The odd one out: tax falls only on the value in
     * EXCESS of the ₹50 lakh aggregate, never on the whole of it. Buying ₹52 lakh withholds
     * 0.1% of ₹2 lakh, not of ₹52 lakh. (Its Section 206AA floor is also 5%, not 20% — but
     * that lives in the section row, not here.)
     */
    private function deduct194Q(TdsSection $section, int $basePaise, array $prior, float $rate): array
    {
        $threshold = self::paise($section->threshold_annual);
        $aggregate = $prior['paid'] + $basePaise;

        if ($threshold === null) {
            return $this->aggregateRule($section, $basePaise, $prior, $rate, 'this year');
        }
        if ($aggregate <= $threshold) {
            return [0, sprintf(
                'Aggregate %s is within the %s threshold — no TDS deducted.',
                self::money($aggregate), self::money($threshold),
            ), $basePaise];
        }

        // Tax the excess over the threshold, then subtract what was already withheld.
        $excess = $aggregate - $threshold;
        $due = self::taxOn($excess, $rate);

        return [max(0, $due - $prior['deducted']), sprintf(
            'Aggregate %s exceeds %s — deducted at %s on the excess %s.',
            self::money($aggregate), self::money($threshold), self::pct($rate), self::money($excess),
        ), $excess];
    }

    /**
     * Any section with no coded quirk. A user-added section that carries a single-bill
     * threshold still gets the 194C treatment; one that carries only an aggregate
     * threshold gets the plain rule. Nothing here is hardcoded to a section code.
     */
    private function deductGeneric(TdsSection $section, int $basePaise, array $prior, float $rate, array $ctx): array
    {
        $window = $section->isMonthly() ? 'in '.$ctx['date']->format('M Y') : 'this year';

        return $section->hasSingleThreshold()
            ? $this->singleAndAggregateRule($section, $basePaise, $prior, $rate, $ctx, $window)
            : $this->aggregateRule($section, $basePaise, $prior, $rate, $window);
    }

    /**
     * The shared engine behind every aggregate section. Nothing is withheld while the
     * window's aggregate stays within the threshold. The moment it crosses, tax is due on
     * the ENTIRE aggregate; subtracting what was already withheld this window produces
     * both the one-time catch-up and the ordinary steady-state deduction from one line.
     *
     * @param  array{paid:int,deducted:int}  $prior  the window's state before this payment
     */
    private function aggregateRule(TdsSection $section, int $basePaise, array $prior, float $rate, string $window): array
    {
        $threshold = self::paise($section->threshold_annual);
        $aggregate = $prior['paid'] + $basePaise;

        // No aggregate threshold at all → every rupee is deducted from the first payment.
        if ($threshold === null) {
            $due = self::taxOn($aggregate, $rate);

            return [max(0, $due - $prior['deducted']), sprintf('Deducted at %s (no threshold).', self::pct($rate)), $aggregate];
        }

        if ($aggregate <= $threshold) {
            return [0, sprintf(
                'Aggregate %s %s is within the %s threshold — no TDS deducted.',
                self::money($aggregate), $window, self::money($threshold),
            ), $basePaise];
        }

        $due = self::taxOn($aggregate, $rate);
        $deducted = max(0, $due - $prior['deducted']);

        // The crossing voucher: name the catch-up explicitly, because a user staring at
        // ₹5,500 withheld from a ₹15,000 bill deserves to be told why.
        if ($prior['deducted'] === 0 && $prior['paid'] > 0) {
            return [$deducted, sprintf(
                'Aggregate %s %s crosses the %s threshold — deducted at %s on the full aggregate, '
                .'catching up on %s paid earlier with no deduction.',
                self::money($aggregate), $window, self::money($threshold), self::pct($rate), self::money($prior['paid']),
            ), $aggregate];
        }

        return [$deducted, sprintf(
            'Threshold already crossed %s — deducted at %s on %s.',
            $window, self::pct($rate), self::money($basePaise),
        ), $aggregate];
    }

    /**
     * The rule for a section carrying BOTH a single-transaction and an aggregate threshold
     * (194C). While the aggregate is still within its threshold, only the bills that
     * individually exceed the single threshold are taxed — which is why every TDS-engaged
     * payment writes a tds_deductions row even when it deducted nothing: the small bills
     * have to be distinguishable from the large ones later. Once the aggregate crosses,
     * the ordinary catch-up rule takes over and taxes everything.
     */
    private function singleAndAggregateRule(TdsSection $section, int $basePaise, array $prior, float $rate, array $ctx, string $window): array
    {
        $single = self::paise($section->threshold_single);
        $annual = self::paise($section->threshold_annual);
        $aggregate = $prior['paid'] + $basePaise;

        // Aggregate crossed → tax the whole of it, catching up on the small bills too.
        if ($annual !== null && $aggregate > $annual) {
            $due = self::taxOn($aggregate, $rate);
            $deducted = max(0, $due - $prior['deducted']);

            return [$deducted, sprintf(
                'Aggregate %s %s crosses the %s threshold — deducted at %s on the full aggregate%s.',
                self::money($aggregate), $window, self::money($annual), self::pct($rate),
                $prior['deducted'] === 0 && $prior['paid'] > 0
                    ? ', catching up on '.self::money($prior['paid']).' paid earlier with no deduction'
                    : '',
            ), $aggregate];
        }

        // Aggregate still within its threshold → only the individually-large bills are
        // taxed. Sum the qualifying prior bills, add this one if it qualifies too.
        if ($single === null) {
            return $this->aggregateRule($section, $basePaise, $prior, $rate, $window);
        }

        $qualifying = $this->qualifyingPriorPaise($ctx, $single) + ($basePaise > $single ? $basePaise : 0);

        if ($qualifying === 0) {
            return [0, sprintf(
                'Payment %s is within the %s single-bill threshold and the aggregate %s is within %s — no TDS deducted.',
                self::money($basePaise), self::money($single), self::money($aggregate),
                $annual !== null ? self::money($annual) : 'the aggregate threshold',
            ), $basePaise];
        }

        $due = self::taxOn($qualifying, $rate);
        $deducted = max(0, $due - $prior['deducted']);

        return [$deducted, sprintf(
            'Payment %s exceeds the %s single-bill threshold — deducted at %s on %s. '
            .'The aggregate %s %s is still within %s, so the smaller bills stay untaxed.',
            self::money($basePaise), self::money($single), self::pct($rate), self::money($qualifying),
            self::money($aggregate), $window, $annual !== null ? self::money($annual) : 'the aggregate threshold',
        ), $qualifying];
    }

    /**
     * Σ of the prior payments in this window that INDIVIDUALLY exceeded the single-bill
     * threshold — the bills that were already taxable on their own. Read from the
     * tds_deductions rows, which exist for every TDS-engaged payment, deducted or not.
     */
    private function qualifyingPriorPaise(array $ctx, int $singlePaise): int
    {
        $q = TdsDeduction::query()
            ->where('tds_deductions.deductee_ledger_id', $ctx['deductee_id'])
            ->where('tds_deductions.tds_section_id', $ctx['section_id'])
            ->where('tds_deductions.payment_amount', '>', round($singlePaise / 100, 2))
            ->when($ctx['exclude'], fn ($w) => $w->where('tds_deductions.voucher_id', '!=', $ctx['exclude']));

        if ($ctx['monthly']) {
            $q->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
                ->whereNull('vouchers.scenario_id')
                ->where('vouchers.date', '>=', $ctx['date']->copy()->startOfMonth()->toDateString())
                ->where('vouchers.date', '<=', $ctx['date']->copy()->endOfMonth()->toDateString());
        } else {
            // REAL BOOKS ONLY — a provisional voucher writes a tds_deductions row (only its ytd
            // roll-forward is scenario-gated), so this annual aggregate must exclude it too, or a
            // what-if payment would inflate the qualifying prior for a real voucher's TDS.
            $q->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
                ->whereNull('vouchers.scenario_id')
                ->where('tds_deductions.fy_start', $ctx['fy_start']);
        }

        $sum = 0;
        foreach ($q->get(['tds_deductions.payment_amount']) as $row) {
            $sum += self::paise($row->payment_amount) ?? 0;
        }

        return $sum;
    }

    // ---- prior state ----------------------------------------------------------

    /**
     * The fiscal year's running state before this payment. `tds_deductee_ytd` is the
     * authority; on ALTER we subtract the voucher's OWN recorded contribution, so it is
     * re-derived as though it were the year's latest payment. (Re-deriving every LATER
     * voucher chronologically is out of scope — see the Phase 10A README.)
     *
     * @return array{paid:int,deducted:int}  paise
     */
    private function priorStateForYear(int $deducteeId, int $sectionId, int $fyStart, ?int $excludeVoucherId): array
    {
        $ytd = TdsDeducteeYtd::where('deductee_ledger_id', $deducteeId)
            ->where('tds_section_id', $sectionId)
            ->where('fy_start', $fyStart)
            ->first();

        $paid = self::paise($ytd?->paid_amount) ?? 0;
        $deducted = self::paise($ytd?->deducted_amount) ?? 0;

        if ($excludeVoucherId) {
            $own = TdsDeduction::where('voucher_id', $excludeVoucherId)
                ->where('deductee_ledger_id', $deducteeId)
                ->where('tds_section_id', $sectionId)
                ->where('fy_start', $fyStart)
                ->get();
            foreach ($own as $row) {
                $paid -= self::paise($row->payment_amount) ?? 0;
                $deducted -= self::paise($row->deducted_amount) ?? 0;
            }
        }

        return ['paid' => max(0, $paid), 'deducted' => max(0, $deducted)];
    }

    /**
     * The CALENDAR MONTH's running state before this payment, for a monthly-threshold
     * section (194I). The year-to-date row cannot answer a per-month question, so the
     * month is reconstructed from the tds_deductions rows themselves — which is exactly
     * why a row is written even when nothing was deducted.
     *
     * @return array{paid:int,deducted:int}  paise
     */
    private function priorStateForMonth(int $deducteeId, int $sectionId, Carbon $paymentDate, ?int $excludeVoucherId): array
    {
        $rows = TdsDeduction::query()
            ->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->where('tds_deductions.deductee_ledger_id', $deducteeId)
            ->where('tds_deductions.tds_section_id', $sectionId)
            ->where('vouchers.date', '>=', $paymentDate->copy()->startOfMonth()->toDateString())
            ->where('vouchers.date', '<=', $paymentDate->copy()->endOfMonth()->toDateString())
            ->when($excludeVoucherId, fn ($q) => $q->where('tds_deductions.voucher_id', '!=', $excludeVoucherId))
            ->get(['tds_deductions.payment_amount', 'tds_deductions.deducted_amount']);

        $paid = 0;
        $deducted = 0;
        foreach ($rows as $r) {
            $paid += self::paise($r->payment_amount) ?? 0;
            $deducted += self::paise($r->deducted_amount) ?? 0;
        }

        return ['paid' => max(0, $paid), 'deducted' => max(0, $deducted)];
    }

    // ---- server authority (the after-hook on the shared posting path) ---------

    /**
     * Recompute the expected deduction from the payload and reject if the posted TDS
     * Payable line does not match to the paise. Called from VoucherScreen::validatePayload()'s
     * after() step, exactly like the GST/VAT/bill-wise/cost-centre hooks.
     *
     * Returns null when TDS is off, when the voucher declares no deduction and posts no
     * TDS line, or when everything checks out. The client's tax is never trusted.
     */
    public function verifyPayload(array $payload): ?string
    {
        $lines = $payload['lines'] ?? [];
        $declared = $payload['tds_deduction'] ?? null;
        $postedTds = $this->postedTdsPaise($lines);

        if (! $this->enabled()) {
            // Feature off: a TDS Payable line must not appear on any voucher.
            return $postedTds > 0
                ? 'TDS is not enabled for this company (F11) — the TDS Payable line cannot be posted.'
                : null;
        }

        if (! is_array($declared) || empty($declared['deductee_ledger_id']) || empty($declared['tds_section_id'])) {
            // Nothing declared → nothing may be posted. A crafted payload cannot smuggle
            // a credit into TDS Payable without going through the engine.
            return $postedTds > 0
                ? 'A TDS Payable line was posted without a TDS deduction. Choose the deductee and the section.'
                : null;
        }

        if (($payload['type'] ?? null) !== 'payment') {
            return 'TDS can only be deducted on a Payment voucher.';
        }
        if (empty($payload['date'])) {
            return null; // the `date` rule already rejected this voucher; don't guess a year.
        }
        if (! $this->payableLedgerId()) {
            return 'The TDS Payable ledger is missing. Re-seed the chart of accounts.';
        }

        $deducteeId = (int) $declared['deductee_ledger_id'];
        $sectionId = (int) $declared['tds_section_id'];
        $deductee = Ledger::find($deducteeId);
        $section = TdsSection::find($sectionId);

        if (! $deductee) {
            return 'The deductee ledger no longer exists.';
        }
        if (! $section) {
            return 'The TDS section no longer exists.';
        }

        $date = Carbon::parse($payload['date']);
        $fyStart = Voucher::statutoryFyStartFor($date);

        // A section that was repealed (or not yet enacted) cannot be used on a voucher of
        // that fiscal year. This is what keeps a 194J voucher out of FY 2026-27, where
        // Section 393 governs, and a 393-194J voucher out of the years before it existed.
        if (! $section->isEffectiveFor($fyStart)) {
            return sprintf(
                'Section %s is not in force for FY %s (it applies %s). Choose the section effective for this voucher’s year.',
                $section->code, Voucher::statutoryFyLabel($fyStart), $section->effectiveLabel(),
            );
        }

        // THE GST-BASE RULE, enforced: the base is the voucher's non-duty debit total.
        // A declared base that disagrees is rejected — the client cannot widen the base
        // to include GST, nor narrow it to under-deduct.
        $expectedBase = $this->basePaiseFromLines($lines);
        $declaredBase = (int) round(((float) ($declared['base_amount'] ?? 0)) * 100);

        if ($expectedBase <= 0) {
            return 'A TDS deduction needs at least one debit to a non-tax expense ledger.';
        }
        if ($declaredBase !== $expectedBase) {
            return sprintf(
                'TDS base mismatch: expected %s (the voucher’s expense debits, excluding GST), got %s. '
                .'TDS is deducted on the value excluding GST.',
                self::money($expectedBase), self::money($declaredBase),
            );
        }

        // Exactly one credit line may carry the deduction; the rest of the credit side is
        // the bank/cash the vendor is paid from.
        $excludeVoucherId = ! empty($payload['voucher_id']) ? (int) $payload['voucher_id'] : null;
        [$expected, $reason] = $this->computeDeduction($deducteeId, $sectionId, $expectedBase, $fyStart, $excludeVoucherId, $date);

        if ($expected !== $postedTds) {
            return sprintf(
                'TDS mismatch on %s: expected %s, got %s. %s TDS is computed on the server from the '
                .'section rate, the deductee’s threshold state and the taxable base.',
                $section->code, self::money($expected), self::money($postedTds), $reason,
            );
        }

        return null;
    }

    // ---- persistence (inside the shared voucher transaction) -----------------

    /**
     * Record the deduction for a freshly written voucher and roll the deductee's running
     * state forward. Called from VoucherScreen::writeVoucherGraph() / persistAlter(),
     * inside the same transaction as the entries — so the threshold state and the ledger
     * postings commit or roll back together, and a concurrent post can never read a
     * half-applied threshold.
     *
     * @param  array<int,int>  $lineEntryIds  line index => created VoucherEntry id
     */
    public function persist(Voucher $voucher, array $payload, array $lineEntryIds = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        $declared = $payload['tds_deduction'] ?? null;
        if (! is_array($declared) || empty($declared['deductee_ledger_id']) || empty($declared['tds_section_id'])) {
            return;
        }
        if ($voucher->type !== 'payment') {
            return;
        }

        $lines = $payload['lines'] ?? [];
        $deducteeId = (int) $declared['deductee_ledger_id'];
        $sectionId = (int) $declared['tds_section_id'];
        $section = TdsSection::find($sectionId);
        $deductee = Ledger::find($deducteeId);
        if (! $section || ! $deductee) {
            return;
        }

        $date = Carbon::parse($voucher->date);
        $fyStart = Voucher::statutoryFyStartFor($date);
        $basePaise = $this->basePaiseFromLines($lines);

        // Recompute from scratch — never carry a number across from validation. persist()
        // runs after reverseFor() on the alter path, so the voucher's own contribution is
        // already out of the running state and no exclusion is needed here.
        $d = $this->computeDetailed($deducteeId, $sectionId, $basePaise, $fyStart, null, $date);
        $deducted = $d['deducted'];

        TdsDeduction::create([
            'voucher_id' => $voucher->id,
            'voucher_entry_id' => $this->tdsEntryId($voucher, $lines, $lineEntryIds),
            'deductee_ledger_id' => $deducteeId,
            'tds_section_id' => $sectionId,
            // This voucher's own taxable base (what Form 26Q calls "amount paid")…
            'payment_amount' => round($basePaise / 100, 2),
            // …versus the cumulative base the liability was computed on. They differ on
            // the voucher that crosses a threshold, and that difference IS the catch-up.
            'base_amount' => round($d['base'] / 100, 2),
            'rate' => $d['rate'],
            'deducted_amount' => round($deducted / 100, 2),
            'fy_start' => $fyStart,
            'reason' => mb_substr($d['reason'], 0, 255),
        ]);

        // Roll the fiscal-year state forward — REAL BOOKS ONLY. paid_amount always
        // accumulates this payment's own base; deducted_amount accumulates what was
        // actually withheld. A provisional voucher (scenario_id set) computes against
        // real prior state but must never mutate it, so skip the roll-forward.
        if ($voucher->scenario_id === null) {
            $ytd = TdsDeducteeYtd::firstOrCreate(
                ['deductee_ledger_id' => $deducteeId, 'tds_section_id' => $sectionId, 'fy_start' => $fyStart],
                ['paid_amount' => 0, 'deducted_amount' => 0],
            );
            $ytd->paid_amount = round(((self::paise($ytd->paid_amount) ?? 0) + $basePaise) / 100, 2);
            $ytd->deducted_amount = round(((self::paise($ytd->deducted_amount) ?? 0) + $deducted) / 100, 2);
            $ytd->save();
        }
    }

    /**
     * Undo a voucher's TDS contribution: subtract it from the deductee's running state and
     * delete the deduction row. Called on the alter path before re-persisting, and from
     * Voucher's `deleting` hook on cancel — before the FK cascade removes the rows, so the
     * amounts are still readable.
     */
    public function reverseFor(Voucher $voucher): void
    {
        $rows = TdsDeduction::where('voucher_id', $voucher->id)->get();
        if ($rows->isEmpty()) {
            return;
        }

        // REAL BOOKS ONLY — a provisional voucher (scenario_id set) never rolled ytd
        // forward, so reversing one must not subtract from real year-to-date state.
        if ($voucher->scenario_id === null) {
            foreach ($rows as $row) {
                $ytd = TdsDeducteeYtd::where('deductee_ledger_id', $row->deductee_ledger_id)
                    ->where('tds_section_id', $row->tds_section_id)
                    ->where('fy_start', $row->fy_start)
                    ->first();

                if ($ytd) {
                    $paid = (self::paise($ytd->paid_amount) ?? 0) - (self::paise($row->payment_amount) ?? 0);
                    $deducted = (self::paise($ytd->deducted_amount) ?? 0) - (self::paise($row->deducted_amount) ?? 0);
                    $ytd->paid_amount = round(max(0, $paid) / 100, 2);
                    $ytd->deducted_amount = round(max(0, $deducted) / 100, 2);
                    $ytd->save();
                }
            }
        }

        TdsDeduction::where('voucher_id', $voucher->id)->delete();
    }

    /**
     * Phase 15C — roll a just-promoted voucher's EXISTING TDS deduction into the real ytd.
     *
     * A provisional TDS payment already wrote its tds_deductions row at draft time (matching the
     * Cr TDS Payable line it actually posted) but skipped the ytd roll-forward. On promotion we roll
     * ytd by that FROZEN, general-ledger-consistent amount — we deliberately do NOT recompute, because
     * the voucher's posted TDS Payable line is fixed, so a recomputed (e.g. threshold-catch-up) amount
     * would make tds_deductions / ytd disagree with the GL. A no-op when the voucher has no deduction.
     */
    public function rollYtdForPromotedVoucher(Voucher $voucher): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach (TdsDeduction::where('voucher_id', $voucher->id)->get() as $row) {
            $ytd = TdsDeducteeYtd::firstOrCreate(
                ['deductee_ledger_id' => $row->deductee_ledger_id, 'tds_section_id' => $row->tds_section_id, 'fy_start' => $row->fy_start],
                ['paid_amount' => 0, 'deducted_amount' => 0],
            );
            $ytd->paid_amount = round(((self::paise($ytd->paid_amount) ?? 0) + (self::paise($row->payment_amount) ?? 0)) / 100, 2);
            $ytd->deducted_amount = round(((self::paise($ytd->deducted_amount) ?? 0) + (self::paise($row->deducted_amount) ?? 0)) / 100, 2);
            $ytd->save();
        }
    }

    // ---- Phase 10B: challan (remittance) capture ------------------------------

    /**
     * True when a voucher DEBITS the TDS Payable ledger — i.e. it is a remittance of
     * withheld tax to the government (Dr TDS Payable / Cr Bank), the case that carries a
     * bank challan identifier.
     */
    public function debitsPayable(array $lines): bool
    {
        $payableId = $this->payableLedgerId();
        if (! $payableId) {
            return false;
        }
        foreach ($lines as $line) {
            if ((int) ($line['ledger_id'] ?? 0) === $payableId && ($line['dr_cr'] ?? null) === 'Dr') {
                return true;
            }
        }

        return false;
    }

    /**
     * Server authority for a captured challan (Phase 10B). A challan identifier may only
     * ride a Payment that debits TDS Payable, and its BSR / challan number must be
     * well-formed. Returns null when there is no challan or it is valid; a message otherwise.
     */
    public function verifyChallanPayload(array $payload): ?string
    {
        $ch = $payload['tds_challan'] ?? null;
        if (! is_array($ch) || empty($ch['bsr_code']) && empty($ch['challan_number']) && empty($ch['deposit_date'])) {
            return null; // nothing captured
        }
        if (($payload['type'] ?? null) !== 'payment') {
            return 'A TDS challan can only be recorded on a Payment voucher.';
        }
        if (! $this->debitsPayable($payload['lines'] ?? [])) {
            return 'A TDS challan can only be recorded on a remittance that debits the TDS Payable ledger.';
        }
        if (! preg_match('/^[0-9]{7}$/', (string) ($ch['bsr_code'] ?? ''))) {
            return 'The BSR code must be exactly 7 digits.';
        }
        if (! preg_match('/^[0-9]{1,5}$/', (string) ($ch['challan_number'] ?? ''))) {
            return 'The bank challan number must be 1-5 digits.';
        }
        if (empty($ch['deposit_date'])) {
            return 'The challan deposit date is required.';
        }

        return null;
    }

    /**
     * Record the bank challan for a remittance voucher, inside its post transaction. The
     * deposited amount is the voucher's own Dr TDS Payable total (server-derived, never the
     * client's word). A no-op unless the voucher debits TDS Payable and a challan is given.
     */
    public function persistChallan(Voucher $voucher, array $payload): void
    {
        if (! $this->enabled() || $voucher->type !== 'payment') {
            return;
        }
        $ch = $payload['tds_challan'] ?? null;
        if (! is_array($ch) || empty($ch['bsr_code'])) {
            return;
        }
        $lines = $payload['lines'] ?? [];
        if (! $this->debitsPayable($lines)) {
            return;
        }

        $payableId = $this->payableLedgerId();
        $depositPaise = 0;
        foreach ($lines as $line) {
            if ((int) ($line['ledger_id'] ?? 0) === $payableId && ($line['dr_cr'] ?? null) === 'Dr') {
                $depositPaise += (int) round(((float) ($line['amount'] ?? 0)) * 100);
            }
        }

        \App\Models\TdsChallan::updateOrCreate(
            ['voucher_id' => $voucher->id],
            [
                'bsr_code' => (string) $ch['bsr_code'],
                'challan_number' => (string) ($ch['challan_number'] ?? ''),
                'deposit_date' => $ch['deposit_date'],
                'total_amount' => round($depositPaise / 100, 2),
                'minor_head' => $ch['minor_head'] ?? '200',
            ],
        );
    }

    /** Delete a voucher's challan record (alter reset; cancel cascades via the FK). */
    public function reverseChallanFor(Voucher $voucher): void
    {
        \App\Models\TdsChallan::where('voucher_id', $voucher->id)->delete();
    }

    /** The saved challan of a voucher, for re-opening it on the alter screen. */
    public function challanForVoucher(int $voucherId): ?array
    {
        $row = \App\Models\TdsChallan::where('voucher_id', $voucherId)->first();

        return $row ? [
            'bsr_code' => $row->bsr_code,
            'challan_number' => $row->challan_number,
            'deposit_date' => $row->deposit_date?->toDateString(),
            'minor_head' => $row->minor_head,
        ] : null;
    }

    /** The created VoucherEntry id of the Cr TDS Payable line, or null when nothing was deducted. */
    private function tdsEntryId(Voucher $voucher, array $lines, array $lineEntryIds): ?int
    {
        $payableId = $this->payableLedgerId();
        foreach ($lines as $i => $line) {
            if ((int) ($line['ledger_id'] ?? 0) === $payableId && ($line['dr_cr'] ?? null) === 'Cr') {
                return $lineEntryIds[$i] ?? null;
            }
        }

        return null;
    }

    /** The saved deduction of a voucher, for re-opening it on the alter screen. */
    public function deductionForVoucher(int $voucherId): ?array
    {
        $row = TdsDeduction::with(['deductee', 'section'])->where('voucher_id', $voucherId)->first();
        if (! $row) {
            return null;
        }

        return [
            'deductee_ledger_id' => $row->deductee_ledger_id,
            'deductee_label' => $row->deductee?->name,
            'tds_section_id' => $row->tds_section_id,
            'section_label' => $row->section?->displayLabel(),
            'payment_amount' => (float) $row->payment_amount,
            'base_amount' => (float) $row->base_amount,
            'rate' => (float) $row->rate,
            'deducted_amount' => (float) $row->deducted_amount,
            'reason' => $row->reason,
        ];
    }

    // ---- TDS Deduction Summary report ----------------------------------------

    /**
     * Per section, per deductee: the base paid, the TDS deducted, and how much of that is
     * still owed to the Revenue.
     *
     * Remitting TDS is an ordinary Payment against the TDS Payable ledger (Dr TDS Payable
     * / Cr Bank) — one challan clears many deductions and carries no section tag, so a
     * remittance cannot be attributed to a section directly. It is therefore allocated
     * FIFO across the fiscal year's deductions in (voucher date, id) order, which is how
     * a challan actually discharges a liability. The allocation reconciles exactly:
     *
     *     Σ row.outstanding  ==  the TDS Payable ledger's closing credit balance
     *
     * and `zerobook:prove-tds` asserts precisely that identity.
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $fyStart = Voucher::statutoryFyStartFor($to);

        // Every deduction of the fiscal year, oldest first — the FIFO queue a remittance
        // discharges. The report only DISPLAYS the ones inside the period, but the
        // outstanding figure has to know about the ones before it.
        $all = TdsDeduction::query()
            ->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->leftJoin('ledgers', 'ledgers.id', '=', 'tds_deductions.deductee_ledger_id')
            ->leftJoin('tds_sections', 'tds_sections.id', '=', 'tds_deductions.tds_section_id')
            ->where('tds_deductions.fy_start', $fyStart)
            ->orderBy('vouchers.date')->orderBy('tds_deductions.id')
            ->get([
                'tds_deductions.id',
                'tds_deductions.voucher_id',
                'tds_deductions.deductee_ledger_id',
                'tds_deductions.tds_section_id',
                'tds_deductions.payment_amount',
                'tds_deductions.deducted_amount',
                'tds_deductions.rate',
                'vouchers.date as v_date',
                'ledgers.name as deductee_name',
                'ledgers.deductee_pan as deductee_pan',
                'tds_sections.code as section_code',
                'tds_sections.label as section_label',
            ]);

        // Remittances: debits to TDS Payable up to the report's end date, in the same FY.
        $remittedPaise = $this->remittedPaise($fyStart, $to);

        // Allocate the remittance across the year's deductions, oldest first.
        $pool = $remittedPaise;
        $outstandingById = [];
        foreach ($all as $r) {
            $ded = self::paise($r->deducted_amount) ?? 0;
            $cleared = min($pool, $ded);
            $pool -= $cleared;
            $outstandingById[$r->id] = $ded - $cleared;
        }

        // Aggregate the in-period rows by section, then deductee.
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $sections = [];
        foreach ($all as $r) {
            $d = $r->v_date instanceof Carbon ? $r->v_date->toDateString() : (string) $r->v_date;
            if ($d < $fromStr || $d > $toStr) {
                continue;
            }
            $sid = (int) $r->tds_section_id;
            $did = (int) $r->deductee_ledger_id;

            $sections[$sid] ??= [
                'tds_section_id' => $sid,
                'code' => $r->section_code,
                'label' => $r->section_label,
                'deductees' => [],
                'base_paise' => 0,
                'deducted_paise' => 0,
                'outstanding_paise' => 0,
            ];
            $sections[$sid]['deductees'][$did] ??= [
                'deductee_ledger_id' => $did,
                'name' => $r->deductee_name ?? '(deductee)',
                'pan' => $r->deductee_pan,
                'rate' => (float) $r->rate,
                'base_paise' => 0,
                'deducted_paise' => 0,
                'outstanding_paise' => 0,
                'voucher_count' => 0,
            ];

            $base = self::paise($r->payment_amount) ?? 0;
            $ded = self::paise($r->deducted_amount) ?? 0;
            $out = $outstandingById[$r->id] ?? 0;

            $sections[$sid]['deductees'][$did]['base_paise'] += $base;
            $sections[$sid]['deductees'][$did]['deducted_paise'] += $ded;
            $sections[$sid]['deductees'][$did]['outstanding_paise'] += $out;
            $sections[$sid]['deductees'][$did]['voucher_count']++;
            $sections[$sid]['base_paise'] += $base;
            $sections[$sid]['deducted_paise'] += $ded;
            $sections[$sid]['outstanding_paise'] += $out;
        }

        // Present.
        $rows = [];
        $totalBase = 0;
        $totalDeducted = 0;
        $totalOutstanding = 0;
        ksort($sections);
        foreach ($sections as $s) {
            $deductees = array_values($s['deductees']);
            usort($deductees, fn ($a, $b) => strcmp($a['name'], $b['name']));

            $rows[] = [
                'kind' => 'section',
                'tds_section_id' => $s['tds_section_id'],
                'deductee_ledger_id' => null,
                'label' => $s['code'],
                'sub' => $s['label'],
                'pan' => '',
                'rate' => '',
                'base' => BalanceService::money($s['base_paise']),
                'deducted' => BalanceService::money($s['deducted_paise']),
                'outstanding' => BalanceService::money($s['outstanding_paise']),
            ];
            foreach ($deductees as $d) {
                $rows[] = [
                    'kind' => 'deductee',
                    'tds_section_id' => $s['tds_section_id'],
                    'deductee_ledger_id' => $d['deductee_ledger_id'],
                    'label' => $d['name'],
                    'sub' => $d['voucher_count'].' payment'.($d['voucher_count'] === 1 ? '' : 's'),
                    'pan' => $d['pan'] ?: '— no PAN —',
                    'rate' => self::pct($d['rate']),
                    'base' => BalanceService::money($d['base_paise']),
                    'deducted' => BalanceService::money($d['deducted_paise']),
                    'outstanding' => BalanceService::money($d['outstanding_paise']),
                ];
            }
            $rows[] = [
                'kind' => 'subtotal',
                'tds_section_id' => null,
                'deductee_ledger_id' => null,
                'label' => 'Total for '.$s['code'],
                'sub' => '',
                'pan' => '',
                'rate' => '',
                'base' => BalanceService::money($s['base_paise']),
                'deducted' => BalanceService::money($s['deducted_paise']),
                'outstanding' => BalanceService::money($s['outstanding_paise']),
            ];

            $totalBase += $s['base_paise'];
            $totalDeducted += $s['deducted_paise'];
            $totalOutstanding += $s['outstanding_paise'];
        }

        return [
            'rows' => $rows,
            'empty' => $rows === [],
            'total_base' => BalanceService::money($totalBase),
            'total_deducted' => BalanceService::money($totalDeducted),
            'total_outstanding' => BalanceService::money($totalOutstanding),
            'total_base_paise' => $totalBase,
            'total_deducted_paise' => $totalDeducted,
            'total_outstanding_paise' => $totalOutstanding,
            'remitted' => BalanceService::money($remittedPaise),
            'remitted_paise' => $remittedPaise,
            // The company-level liability: what the TDS Payable ledger actually says.
            // Σ of the rows' `outstanding` reconciles to this exactly (prove-tds asserts it).
            'payable_closing' => BalanceService::money($payableClosing = $this->payableClosingPaise($to)),
            'payable_closing_paise' => $payableClosing,
            'fy_label' => Voucher::statutoryFyLabel($fyStart),
            'from' => $from->format('d-M-Y'),
            'to' => $to->format('d-M-Y'),
        ];
    }

    /** Σ debits to the TDS Payable ledger within the fiscal year, up to `to` — i.e. remittances. */
    private function remittedPaise(int $fyStart, Carbon $to): int
    {
        $payableId = $this->payableLedgerId();
        if (! $payableId) {
            return 0;
        }

        $fyOpen = Carbon::create($fyStart, 4, 1);

        $sum = \App\Models\VoucherEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->where('voucher_entries.ledger_id', $payableId)
            ->where('voucher_entries.dr_cr', 'Dr')
            ->where('vouchers.date', '>=', $fyOpen->toDateString())
            ->where('vouchers.date', '<=', $to->toDateString())
            ->sum('voucher_entries.amount');

        return (int) round(((float) $sum) * 100);
    }

    /** The TDS Payable ledger's closing balance as on a date, presented Cr-positive (a liability). */
    public function payableClosingPaise(Carbon $asOf): int
    {
        $payableId = $this->payableLedgerId();
        if (! $payableId) {
            return 0;
        }
        // ledgerClosings() is Dr-terms; a liability closes Cr, so flip the sign.
        return -(app(BalanceService::class)->ledgerClosings($asOf)[$payableId] ?? 0);
    }

    /** The payment vouchers behind one (section, deductee) pair in the period — the drill list. */
    public function deducteeVouchers(int $sectionId, int $deducteeId, Carbon $from, Carbon $to): array
    {
        $rows = TdsDeduction::query()
            ->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->where('tds_deductions.tds_section_id', $sectionId)
            ->where('tds_deductions.deductee_ledger_id', $deducteeId)
            ->where('vouchers.date', '>=', $from->toDateString())
            ->where('vouchers.date', '<=', $to->toDateString())
            ->orderBy('vouchers.date')->orderBy('tds_deductions.id')
            ->get([
                'tds_deductions.voucher_id',
                'tds_deductions.payment_amount',
                'tds_deductions.base_amount',
                'tds_deductions.rate',
                'tds_deductions.deducted_amount',
                'tds_deductions.reason',
                'vouchers.type as v_type',
                'vouchers.number as v_number',
                'vouchers.date as v_date',
            ]);

        return $rows->map(fn ($r) => [
            'voucher_id' => (int) $r->voucher_id,
            'display_number' => strtoupper(Voucher::TYPES[$r->v_type]['abbr'] ?? $r->v_type).'-'.$r->v_number,
            'date' => Carbon::parse($r->v_date)->format('d-M-Y'),
            'payment' => BalanceService::money(self::paise($r->payment_amount) ?? 0),
            'base' => BalanceService::money(self::paise($r->base_amount) ?? 0),
            'rate' => self::pct((float) $r->rate),
            'deducted' => BalanceService::money(self::paise($r->deducted_amount) ?? 0),
            'reason' => $r->reason,
        ])->all();
    }

    // ---- paise helpers --------------------------------------------------------

    /** Tax on a base, in paise. The one place a rate meets an amount. */
    private static function taxOn(int $basePaise, float $rate): int
    {
        return (int) round($basePaise * $rate / 100);
    }

    /** Rupee-decimal (or null) => integer paise (or null). */
    private static function paise($amount): ?int
    {
        return $amount === null ? null : (int) round(((float) $amount) * 100);
    }

    private static function money(int $paise): string
    {
        return number_format($paise / 100, 2);
    }

    /** "10%" / "0.1%" / "2.5%" — trailing zeros trimmed, because 10.00% reads like noise. */
    private static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%';
    }
}
