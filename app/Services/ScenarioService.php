<?php

namespace App\Services;

use App\Exceptions\ScenarioPromotionException;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\Scenario;
use App\Models\ScenarioPromotion;
use App\Models\TdsSection;
use App\Models\Voucher;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 15C — the scenario layer.
 *
 * A scenario is a named container of PROVISIONAL vouchers (vouchers.scenario_id set). They are
 * tagged, never a separate posting path: the same VoucherScreen::post writes them, the same
 * services read them — reads are widened to include a scenario only when the caller opts in
 * through {@see ScenarioContext}. This service owns the lifecycle around that tag: CRUD, the
 * report picker's session selection, the one-way transactional promotion into the real books,
 * and the impact report that contrasts the real books against real+scenario.
 */
class ScenarioService
{
    public function __construct(private BalanceService $balances, private TdsService $tds) {}

    // ---- feature gate + listing ------------------------------------------------

    public function enabled(): bool
    {
        return (bool) (CompanyFeature::current()->scenarios ?? false);
    }

    /** Active scenarios for the current company — the set offered in every report picker. */
    public function activeScenarios(): Collection
    {
        return Scenario::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** Every scenario incl. deactivated/promoted ones — for the manager screen. */
    public function allScenarios(): Collection
    {
        return Scenario::query()->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function find(int $id): ?Scenario
    {
        return Scenario::query()->find($id);
    }

    // ---- CRUD ------------------------------------------------------------------

    public function create(string $name, ?string $description, ?int $userId): Scenario
    {
        $name = trim($name);

        return Scenario::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'description' => $description !== null && $description !== '' ? trim($description) : null,
            'created_by_user_id' => $userId,
            'is_active' => true,
        ]);
    }

    public function rename(Scenario $scenario, string $name, ?string $description): Scenario
    {
        $name = trim($name);
        if ($name !== $scenario->name) {
            $scenario->name = $name;
            $scenario->slug = $this->uniqueSlug($name, $scenario->id);
        }
        $scenario->description = $description !== null && $description !== '' ? trim($description) : null;
        $scenario->save();

        return $scenario;
    }

    public function setActive(Scenario $scenario, bool $active): void
    {
        $scenario->is_active = $active;
        $scenario->save();
    }

    /**
     * Typed-delete. Removes the scenario AND its provisional vouchers. Deleting each voucher
     * fires the model's own `deleting` hook (stock refold, TDS reverse — the TDS ytd subtract
     * is a no-op for a provisional voucher, which never rolled ytd forward) and cascades its
     * entries. Real books are untouched because only scenario-tagged vouchers are removed.
     */
    public function delete(Scenario $scenario): int
    {
        return DB::transaction(function () use ($scenario) {
            $count = 0;
            foreach ($scenario->vouchers()->get() as $voucher) {
                $voucher->delete();
                $count++;
            }
            $scenario->delete();

            return $count;
        });
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'scenario';
        $slug = $base;
        $i = 2;
        while (
            Scenario::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // ---- report picker: session selection --------------------------------------

    /**
     * The scenario ids the user has switched ON for reports this session, filtered to ids that
     * are still active scenarios in the CURRENT company (company scoping — a stale id from
     * another company or a since-deactivated scenario silently drops). Empty => real books only.
     *
     * @return int[]
     */
    public function sessionSelected(): array
    {
        $ids = array_filter(array_map('intval', (array) session('scenario.selected', [])));
        if (! $ids) {
            return [];
        }

        return Scenario::query()
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->values()
            ->all();
    }

    /**
     * Persist the report picker choice. Invalid / cross-company / inactive ids are dropped.
     *
     * @param  int[]  $ids
     * @return int[] the accepted ids
     */
    public function setSessionSelected(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));

        $valid = $ids
            ? Scenario::query()
                ->where('is_active', true)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($i) => (int) $i)
                ->values()
                ->all()
            : [];

        session(['scenario.selected' => $valid]);

        return $valid;
    }

    public function clearSession(): void
    {
        session()->forget('scenario.selected');
    }

    /**
     * Names for the inclusion banner shown on every report when scenarios are active.
     *
     * @param  int[]  $ids
     * @return string[]
     */
    public function selectedLabels(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return Scenario::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    // ---- promotion: one-way, transactional -------------------------------------

    /**
     * Promote every provisional voucher in a scenario into the real books. Re-runs post-time
     * validation for each voucher first; if ANY fails, the whole transaction rolls back and
     * nothing changes. On success each voucher is un-tagged (scenario_id -> null), stamped with
     * promoted_from_scenario_id, and — for TDS-deducting payments — its deduction is recomputed
     * against the now-real state so real year-to-date threshold state rolls forward exactly once.
     * NEVER reversible.
     *
     * @return array{count:int, promotion:ScenarioPromotion}
     *
     * @throws ScenarioPromotionException with per-voucher reasons on validation failure.
     */
    public function promote(Scenario $scenario, ?int $userId): array
    {
        return DB::transaction(function () use ($scenario, $userId) {
            /** @var Collection<int,Voucher> $vouchers */
            $vouchers = $scenario->vouchers()
                ->with('entries')
                ->lockForUpdate()
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            if ($vouchers->isEmpty()) {
                throw new ScenarioPromotionException(['*' => 'This scenario has no provisional vouchers to promote.']);
            }

            // 1) Validate ALL first — a single failure aborts the whole promotion.
            $errors = [];
            foreach ($vouchers as $voucher) {
                if ($reason = $this->validateForPromotion($voucher)) {
                    $errors[$voucher->id] = $this->voucherLabel($voucher).': '.$reason;
                }
            }
            if ($errors) {
                throw new ScenarioPromotionException($errors);
            }

            // 2) Flip each voucher to real, then roll its EXISTING TDS deduction into real ytd.
            foreach ($vouchers as $voucher) {
                $voucher->scenario_id = null;
                $voucher->promoted_from_scenario_id = $scenario->id;
                $voucher->save();

                // A provisional TDS payment already wrote its tds_deductions row (matching the Cr
                // TDS Payable line it posted) but skipped the ytd roll-forward. Roll it now by that
                // frozen, GL-consistent amount — NOT a recompute, which could diverge from the posted
                // line and break the tds_deductions ⇄ ytd ⇄ general-ledger reconciliation. No-op when
                // the voucher carries no deduction.
                $this->tds->rollYtdForPromotedVoucher($voucher);
            }

            // 3) Audit trail (survives even if the scenario itself is later deleted — nullOnDelete).
            $promotion = ScenarioPromotion::create([
                'scenario_id' => $scenario->id,
                'scenario_name' => $scenario->name,
                'promoted_by_user_id' => $userId,
                'voucher_count' => $vouchers->count(),
                'promoted_at' => now(),
            ]);

            // 4) A fully-promoted scenario is now empty and historical — deactivate it so it drops
            //    out of pickers, while its promotion record and the promoted_from stamp survive.
            $scenario->is_active = false;
            $scenario->save();

            return ['count' => $vouchers->count(), 'promotion' => $promotion];
        });
    }

    /**
     * Post-time re-validation of a single provisional voucher: the books still balance, every
     * ledger it touches still exists, and any TDS section it used is still in force. Returns a
     * human reason string on failure, or null when the voucher is safe to promote.
     */
    private function validateForPromotion(Voucher $voucher): ?string
    {
        $entries = $voucher->entries;
        if ($entries->count() < 2) {
            return 'voucher has fewer than two entries';
        }

        $dr = 0;
        $cr = 0;
        $ledgerIds = [];
        foreach ($entries as $e) {
            $paise = (int) round(((float) $e->amount) * 100);
            if ($e->dr_cr === 'Dr') {
                $dr += $paise;
            } else {
                $cr += $paise;
            }
            $ledgerIds[(int) $e->ledger_id] = true;
        }
        if ($dr !== $cr) {
            return 'entries no longer balance (Dr '.number_format($dr / 100, 2).' vs Cr '.number_format($cr / 100, 2).')';
        }

        // Every ledger still exists in the company (a deleted master invalidates the voucher).
        $ids = array_keys($ledgerIds);
        $found = Ledger::query()->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (array_diff($ids, $found)) {
            return 'references a ledger that no longer exists';
        }
        if ($voucher->party_ledger_id && ! Ledger::query()->whereKey($voucher->party_ledger_id)->exists()) {
            return 'party ledger no longer exists';
        }

        // If this voucher deducted TDS, the section must still be in force on its date.
        $ded = $this->tds->deductionForVoucher($voucher->id);
        if ($ded) {
            $section = TdsSection::find($ded['tds_section_id']);
            if (! $section) {
                return 'TDS section no longer exists';
            }
            $fyStart = Voucher::statutoryFyStartFor(Carbon::parse($voucher->date));
            if (! $section->isEffectiveFor($fyStart)) {
                return 'TDS section '.$section->code.' is not in force for this period';
            }
        }

        return null;
    }

    private function voucherLabel(Voucher $voucher): string
    {
        return ucfirst((string) $voucher->type).' #'.$voucher->number.' ('.Carbon::parse($voucher->date)->toDateString().')';
    }

    // ---- impact report: real vs real+scenario ----------------------------------

    /**
     * Contrast the real books against real+scenario as of a date. Headline figures (net profit,
     * income, expenses, assets, liabilities, stock), the per-ledger closing deltas the scenario
     * causes, and the scenario's voucher list for drill-down. Everything is computed twice under
     * an explicit {@see ScenarioContext} so the "real" column is guaranteed actuals-only.
     */
    public function impactReport(Scenario $scenario, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
        $from = Voucher::fyOpenFor(Voucher::fyStartFor($asOf));
        $to = $asOf;

        $real = ScenarioContext::runWith([], fn () => $this->snapshotFigures($from, $to));
        $withScen = ScenarioContext::runWith([$scenario->id], fn () => $this->snapshotFigures($from, $to));

        $headline = [];
        foreach (['net_profit', 'total_income', 'total_expenses', 'total_assets', 'total_liabilities', 'closing_stock'] as $k) {
            $headline[$k] = [
                'real' => $real[$k],
                'scenario' => $withScen[$k],
                'delta' => $withScen[$k] - $real[$k],
            ];
        }

        $vouchers = $scenario->vouchers()
            ->with('entries')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Voucher $v) => [
                'id' => $v->id,
                'date' => Carbon::parse($v->date)->toDateString(),
                'type' => $v->type,
                'number' => $v->number,
                'narration' => $v->narration,
                'amount' => $this->voucherAmountPaise($v),
            ])
            ->all();

        return [
            'scenario' => [
                'id' => $scenario->id,
                'name' => $scenario->name,
                'description' => $scenario->description,
            ],
            'as_of' => $to->toDateString(),
            'headline' => $headline,
            'ledger_delta' => $this->ledgerDeltas($from, $to, $scenario),
            'vouchers' => $vouchers,
            'voucher_count' => count($vouchers),
        ];
    }

    /** @return array<string,int> the six headline figures, in paise. */
    private function snapshotFigures(Carbon $from, Carbon $to): array
    {
        $pl = $this->balances->profitAndLoss($from, $to);
        $bs = $this->balances->balanceSheet($from, $to);

        return [
            'net_profit' => $pl['net'],
            'total_income' => $pl['total_income'],
            'total_expenses' => $pl['total_expenses'],
            'total_assets' => $bs['total_assets'],
            'total_liabilities' => $bs['total_liabilities'],
            'closing_stock' => $bs['closing_stock'],
        ];
    }

    /**
     * Per-ledger closing deltas: the ledgers whose closing balance changes once the scenario is
     * folded in. Real vs scenario closings in Dr-terms paise, sorted by magnitude of change.
     *
     * @return array<int,array{id:int,name:string,real:int,scenario:int,delta:int}>
     */
    private function ledgerDeltas(Carbon $from, Carbon $to, Scenario $scenario): array
    {
        $real = collect(ScenarioContext::runWith([], fn () => $this->balances->ledgerBalances($from, $to)))
            ->keyBy('id');
        $scen = collect(ScenarioContext::runWith([$scenario->id], fn () => $this->balances->ledgerBalances($from, $to)))
            ->keyBy('id');

        $out = [];
        foreach ($scen as $id => $row) {
            $realClosing = (int) ($real[$id]['closing'] ?? 0);
            $delta = (int) $row['closing'] - $realClosing;
            if ($delta !== 0) {
                $out[] = [
                    'id' => (int) $id,
                    'name' => $row['name'],
                    'real' => $realClosing,
                    'scenario' => (int) $row['closing'],
                    'delta' => $delta,
                ];
            }
        }
        usort($out, fn ($a, $b) => abs($b['delta']) <=> abs($a['delta']));

        return $out;
    }

    /** A voucher's headline magnitude = Σ of its Dr entries, in paise. */
    private function voucherAmountPaise(Voucher $voucher): int
    {
        $dr = 0;
        foreach ($voucher->entries as $e) {
            if ($e->dr_cr === 'Dr') {
                $dr += (int) round(((float) $e->amount) * 100);
            }
        }

        return $dr;
    }
}
