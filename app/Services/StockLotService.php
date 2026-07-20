<?php

namespace App\Services;

use App\Models\Ledger;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockLot;
use App\Models\Voucher;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12C-1 → Phase 13 — the stock-lot authority (was InterCompanyLotService).
 *
 * ONE table, TWO purposes:
 *   • INTER-COMPANY provenance (12C-1) — a lot per groupmate receipt; depletion is pure provenance,
 *     the OUT cost stays the weighted average. Consumed by the Lot Ledger + 12C-2 elimination.
 *   • GENERAL FIFO/LIFO costing (13) — a lot per IN movement of a 'fifo'/'lifo' item; here the lot IS
 *     valuation: an OUT costs from the specific lots it depletes (oldest-first FIFO, newest-first LIFO).
 *
 * THE CORRECTNESS SPINE (unchanged) — remaining_qty is a pure function of state:
 *
 *     remaining = replay( real root lots, the item's real OUT + transfer events, chronologically )
 *
 * incrementally maintained at post time and repaired by refoldItem() on alter/cancel.
 *
 * SCENARIO DISCIPLINE (15C): the STORED remaining_qty is the REAL books — a provisional voucher never
 * mutates it (refold + consume force real-only). The READ path (remainingLotsFor) is scenario-aware:
 * default excludes provisional lots; a selected scenario's provisional lots participate.
 *
 * INERT FOR THE WEIGHTED-AVERAGE, UNGROUPED CASE: an ungrouped company with only weighted-average
 * items writes no lots and its OUT path never enters this service.
 */
class StockLotService
{
    /** IN voucher types that can receive inter-company inventory. */
    public const LOT_IN_TYPES = ['purchase', 'receipt_note', 'credit_note', 'rejection_in'];

    /** Counterparty OUT movement types a source voucher can be matched against. */
    private const SOURCE_OUT_MOVEMENTS = ['sale', 'purchase_return'];

    /** Positive-only memo: "db" => true once the lots table has been seen (migration window). */
    private static array $tableSeen = [];

    /**
     * The groupmate SOURCE company of a lot-relevant IN voucher, or null when the voucher writes no
     * inter-company lot: wrong type, no party, party not linked, target not a groupmate, or ungrouped.
     */
    public function lotSourceCompanyId(Voucher $voucher): ?int
    {
        if (! in_array($voucher->type, self::LOT_IN_TYPES, true) || ! $voucher->party_ledger_id) {
            return null;
        }

        $ic = app(InterCompanyService::class);
        if (! $ic->enabled()) {
            return null;
        }

        $linked = Ledger::whereKey($voucher->party_ledger_id)->value('linked_company_id');

        return $linked && in_array((int) $linked, $ic->groupmateIds(), true) ? (int) $linked : null;
    }

    /** True when this voucher's IN rows should write INTER-COMPANY lots (the 12C-1 gate). */
    public function enabledForVoucher(Voucher $voucher): bool
    {
        return $this->tableExists() && $this->lotSourceCompanyId($voucher) !== null;
    }

    /**
     * The lot's depletion-order tag: the item's own method when it is FIFO/LIFO, else 'fifo' — the
     * order 12C-1's provenance fold implements for a weighted-average item receiving inter-company.
     */
    private function lotMethodFor(int $stockItemId): string
    {
        $m = (string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average');

        return in_array($m, ['fifo', 'lifo'], true) ? $m : 'fifo';
    }

    /**
     * Write the provenance lot for one INTER-COMPANY IN row, best-effort matching the counterparty's
     * OUT voucher for source_voucher_id + source_cost_paise. For a FIFO/LIFO item that ALSO receives
     * inter-company, this single lot serves both provenance and costing (its costing_method is fifo/lifo).
     */
    public function writeLotFor(Voucher $voucher, StockEntry $inRow): void
    {
        $sourceCompanyId = $this->lotSourceCompanyId($voucher);
        if ($sourceCompanyId === null || ! $this->tableExists()) {
            return;
        }

        [$sourceVoucherId, $sourceCostPaise] = $this->matchSourceVoucher($sourceCompanyId, $voucher, $inRow);

        StockLot::create([
            'stock_item_id' => $inRow->stock_item_id,
            'godown_id' => $inRow->godown_id,
            'voucher_id' => $voucher->id,
            'stock_entry_id' => $inRow->id,
            'source_company_id' => $sourceCompanyId,
            'source_voucher_id' => $sourceVoucherId,
            'original_qty' => (float) $inRow->quantity,
            'remaining_qty' => (float) $inRow->quantity,
            'source_cost_paise' => $sourceCostPaise,
            'received_rate_paise' => (int) round(((float) $inRow->rate) * 100),
            'received_date' => $voucher->date,
            'costing_method' => $this->lotMethodFor((int) $inRow->stock_item_id),
        ]);
    }

    /**
     * Phase 13 — write a lot for a FIFO/LIFO item's IN movement that is NOT inter-company (no
     * groupmate source). The row's rate is the cost this tranche came onto the books at.
     */
    public function writeLotForRegular(Voucher $voucher, StockEntry $inRow, string $costingMethod): void
    {
        if (! $this->tableExists()) {
            return;
        }

        StockLot::create([
            'stock_item_id' => $inRow->stock_item_id,
            'godown_id' => $inRow->godown_id,
            'voucher_id' => $voucher->id,
            'stock_entry_id' => $inRow->id,
            'source_company_id' => null,
            'source_voucher_id' => null,
            'original_qty' => (float) $inRow->quantity,
            'remaining_qty' => (float) $inRow->quantity,
            'source_cost_paise' => null,
            'received_rate_paise' => (int) round(((float) $inRow->rate) * 100),
            'received_date' => $voucher->date,
            'costing_method' => $costingMethod,
        ]);
    }

    /**
     * INTER-COMPANY provenance depletion for one OUT row (weighted-average item). FIFO by
     * received_date; the OUT's cost is untouched (stays the weighted average). A no-op without lots.
     */
    public function depleteOnOut(Voucher $voucher, StockEntry $outRow, bool $preGated = false): void
    {
        if (! $this->tableExists() || (! $preGated && ! app(InterCompanyService::class)->enabled())) {
            return;
        }

        $this->consume(
            (int) $outRow->stock_item_id,
            $outRow->godown_id !== null ? (int) $outRow->godown_id : null,
            (float) $outRow->quantity,
            (string) $voucher->date,
        );
    }

    /**
     * Phase 13 — cost a FIFO/LIFO OUT row from the specific lots it depletes, and (for a REAL voucher)
     * deplete those lots' remaining_qty. Returns the per-unit cost rate and rewrites the row's
     * rate/value so value = rate × qty holds. Over-depletion (OUT qty > Σ available lots) costs the
     * uncovered units at the item's opening rate and never blocks — Tally allows negative stock.
     *
     * A PROVISIONAL voucher (scenario_id set) costs against a scenario-aware PEEK and mutates NO stored
     * lot state (the real books are inviolate); its own scenario lots participate because post() runs
     * the write under ScenarioContext::runWith([scenario]).
     */
    public function costRegularOut(Voucher $voucher, StockEntry $outRow, string $method): float
    {
        return $this->costOut($outRow, $method === 'lifo', (string) $voucher->date, $voucher->scenario_id === null);
    }

    /** The shared OUT-costing kernel (post path + refold replay). */
    private function costOut(StockEntry $outRow, bool $lifo, string $onOrBefore, bool $mutate): float
    {
        $itemId = (int) $outRow->stock_item_id;
        $godownId = $outRow->godown_id !== null ? (int) $outRow->godown_id : null;
        $qty = (float) $outRow->quantity;

        [$costPaise, $shortfall] = $this->depleteForCost($itemId, $godownId, $qty, $onOrBefore, $lifo, $mutate);

        if ($shortfall > 1e-9) {
            // Over-depletion: cost the uncovered units at the item's opening rate (documented fallback).
            $openingRatePaise = (int) round(((float) (StockItem::whereKey($itemId)->value('opening_rate') ?? 0)) * 100);
            $costPaise += (int) round($shortfall * $openingRatePaise);
        }

        $rate = $qty > 1e-9 ? round($costPaise / $qty / 100, 4) : 0.0;
        $outRow->update(['rate' => $rate, 'value' => round($qty * $rate, 2)]);

        return $rate;
    }

    /**
     * FIFO/LIFO deplete for cost: consume the item's lots in oldest-first (FIFO) or newest-first (LIFO)
     * order, the OUT row's godown first (null godown depletes across all godowns), only lots received
     * on/before the OUT date. Returns [total_cost_paise, uncovered_qty]. Scenario-aware: in the default
     * (real) context it sees only real lots; under a selected scenario it also sees that scenario's lots.
     *
     * @return array{0:int, 1:float}
     */
    private function depleteForCost(int $stockItemId, ?int $godownId, float $qty, string $onOrBefore, bool $lifo, bool $mutate): array
    {
        $lots = StockLot::query()
            ->where('stock_lots.stock_item_id', $stockItemId)
            ->where('stock_lots.remaining_qty', '>', 0)
            ->when($godownId !== null, fn ($q) => $q->where('stock_lots.godown_id', $godownId))
            ->whereDate('stock_lots.received_date', '<=', $onOrBefore)
            ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'stock_lots.voucher_id'))
            ->orderBy('stock_lots.received_date', $lifo ? 'desc' : 'asc')
            ->orderBy('stock_lots.id', $lifo ? 'desc' : 'asc')
            ->get();

        $left = $qty;
        $costPaise = 0;
        foreach ($lots as $lot) {
            if ($left <= 1e-9) {
                break;
            }
            $take = min($left, (float) $lot->remaining_qty);
            $costPaise += (int) round($take * (int) $lot->received_rate_paise);
            if ($mutate) {
                $lot->update(['remaining_qty' => round((float) $lot->remaining_qty - $take, 4)]);
            }
            $left -= $take;
        }

        return [$costPaise, max(0.0, $left)];
    }

    /**
     * A godown transfer MOVES lot allocation: FIFO remaining qty at the source godown splits into child
     * lots at the destination, inheriting the parent's received_date (FIFO order stays the original
     * receipt order) + costing_method + full provenance. Transferred qty beyond the source godown's lot
     * remainder is non-lot inventory — nothing to move.
     */
    public function transferLots(StockEntry $inRow, int $stockItemId, ?int $fromGodownId, ?int $toGodownId, float $qty, ?string $onOrBefore = null): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $lots = StockLot::where('stock_item_id', $stockItemId)
            ->where('remaining_qty', '>', 0)
            ->when($fromGodownId !== null, fn ($q) => $q->where('godown_id', $fromGodownId))
            ->when($fromGodownId === null, fn ($q) => $q->whereNull('godown_id'))
            ->when($onOrBefore !== null, fn ($q) => $q->whereDate('received_date', '<=', $onOrBefore))
            ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'stock_lots.voucher_id'))
            ->orderBy('received_date')->orderBy('id')
            ->get();

        $left = $qty;
        foreach ($lots as $lot) {
            if ($left <= 1e-9) {
                break;
            }
            $take = min($left, (float) $lot->remaining_qty);
            $lot->update(['remaining_qty' => round((float) $lot->remaining_qty - $take, 4)]);

            StockLot::create([
                'stock_item_id' => $stockItemId,
                'godown_id' => $toGodownId,
                'voucher_id' => $inRow->voucher_id,
                'stock_entry_id' => $inRow->id,
                'parent_lot_id' => $lot->id,
                'source_company_id' => $lot->source_company_id,
                'source_voucher_id' => $lot->source_voucher_id,
                'original_qty' => round($take, 4),
                'remaining_qty' => round($take, 4),
                'source_cost_paise' => $lot->source_cost_paise,
                'received_rate_paise' => $lot->received_rate_paise,
                'received_date' => $lot->received_date, // INHERITED — FIFO position kept
                'costing_method' => $lot->costing_method,
            ]);
            $left -= $take;
        }
    }

    /**
     * THE UNIVERSAL REPAIR — recompute an item's whole lot state by replay. Forced real-books (stored
     * lot state is the real books). For a FIFO/LIFO item it ALSO re-costs each OUT stock_entries row
     * from the replayed lots; for a weighted-average item (inter-company provenance only) the OUT cost
     * is untouched. Called after alter/cancel; idempotent; exact.
     */
    public function refoldItem(int $stockItemId): void
    {
        if (! $this->tableExists()) {
            return;
        }
        if (! StockLot::where('stock_item_id', $stockItemId)->exists()) {
            return; // no lots — nothing to repair (the common, free case)
        }

        ScenarioContext::runWith([], function () use ($stockItemId) {
            // Serialise against concurrent posts on the same item (the 6B lock discipline).
            StockItem::whereKey($stockItemId)->lockForUpdate()->get();

            $method = $this->lotMethodForItem($stockItemId);
            $costs = in_array($method, ['fifo', 'lifo'], true);
            $lifo = $method === 'lifo';

            // Reset REAL lots only: delete real child lots, reset real roots to original_qty. A
            // provisional lot is not part of real stored state, so a real-books repair leaves it alone.
            StockLot::where('stock_item_id', $stockItemId)->whereNotNull('parent_lot_id')
                ->whereHas('voucher', fn ($q) => $q->whereNull('scenario_id'))->delete();
            StockLot::where('stock_item_id', $stockItemId)->whereNull('parent_lot_id')
                ->whereHas('voucher', fn ($q) => $q->whereNull('scenario_id'))
                ->update(['remaining_qty' => DB::raw('original_qty')]);

            $events = StockEntry::query()
                ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
                ->whereNull('vouchers.scenario_id') // real-books repair
                ->where('stock_entries.stock_item_id', $stockItemId)
                ->where(function ($q) {
                    $q->where('stock_entries.direction', 'out')
                        ->orWhere('stock_entries.movement_type', 'transfer');
                })
                ->orderBy('vouchers.date')->orderBy('stock_entries.id')
                ->select('stock_entries.*', 'vouchers.date as vdate')
                ->get();

            $transferIns = $events->where('movement_type', 'transfer')->where('direction', 'in')->keyBy('voucher_id');

            foreach ($events as $e) {
                if ($e->movement_type === 'transfer') {
                    if ($e->direction !== 'out') {
                        continue;
                    }
                    $inLeg = $transferIns->get($e->voucher_id);
                    if ($inLeg) {
                        $this->transferLots($inLeg, (int) $e->stock_item_id,
                            $e->godown_id !== null ? (int) $e->godown_id : null,
                            $inLeg->godown_id !== null ? (int) $inLeg->godown_id : null,
                            (float) $e->quantity, (string) $e->vdate);
                    }

                    continue;
                }

                if ($costs) {
                    // FIFO/LIFO: deplete + RE-COST this OUT row from the replayed lots.
                    $this->costOut($e, $lifo, (string) $e->vdate, true);
                } else {
                    // Weighted-average (inter-company provenance): deplete only, cost untouched.
                    $this->consume((int) $e->stock_item_id, $e->godown_id !== null ? (int) $e->godown_id : null, (float) $e->quantity, (string) $e->vdate);
                }
            }
        });
    }

    /** Item costing method, uncached (refold reads it once per item under the lock). */
    private function lotMethodForItem(int $stockItemId): string
    {
        return (string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average');
    }

    /** Refold several items (alter/cancel convenience). */
    public function refoldItems(iterable $stockItemIds): void
    {
        if (! $this->tableExists()) {
            return;
        }

        foreach (array_unique(array_map('intval', collect($stockItemIds)->all())) as $id) {
            if ($id > 0) {
                $this->refoldItem($id);
            }
        }
    }

    /**
     * The remaining lots of an item. asOf = null reads the live stored state; with a date, the state is
     * REPLAYED to that date in memory (nothing mutated). SCENARIO-AWARE: the default (real) context
     * returns only real lots + real depletion; a selected scenario folds in its provisional lots/events.
     *
     * @return array<int, array{lot: StockLot, remaining: float, godown_id: ?int}>
     */
    public function remainingLotsFor(int $stockItemId, ?int $godownId = null, ?Carbon $asOf = null): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        if ($asOf === null) {
            return StockLot::where('stock_item_id', $stockItemId)
                ->when($godownId !== null, fn ($q) => $q->where('godown_id', $godownId))
                ->where('remaining_qty', '>', 0)
                ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'stock_lots.voucher_id'))
                ->orderBy('received_date')->orderBy('id')
                ->get()
                ->map(fn ($lot) => ['lot' => $lot, 'remaining' => (float) $lot->remaining_qty, 'godown_id' => $lot->godown_id !== null ? (int) $lot->godown_id : null])
                ->all();
        }

        // In-memory replay to the date. Roots only — transfers re-derive children.
        $roots = StockLot::where('stock_item_id', $stockItemId)
            ->whereNull('parent_lot_id')
            ->whereDate('received_date', '<=', $asOf)
            ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'stock_lots.voucher_id'))
            ->orderBy('received_date')->orderBy('id')
            ->get();

        $state = $roots->map(fn ($lot) => (object) [
            'lot' => $lot, 'remaining' => (float) $lot->original_qty, 'godown_id' => $lot->godown_id,
        ])->all();

        // LIFO items deplete newest-first; FIFO / weighted-average (inter-company provenance) oldest-
        // first. The replay MUST mirror the post-time depletion order or the as-of value is wrong.
        $lifo = ((string) (StockItem::whereKey($stockItemId)->value('costing_method') ?? 'weighted_average')) === 'lifo';

        $events = StockEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
            ->where('stock_entries.stock_item_id', $stockItemId)
            ->whereDate('vouchers.date', '<=', $asOf)
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->where(function ($q) {
                $q->where('stock_entries.direction', 'out')
                    ->orWhere('stock_entries.movement_type', 'transfer');
            })
            ->orderBy('vouchers.date')->orderBy('stock_entries.id')
            ->select('stock_entries.*')
            ->get();
        $transferIns = $events->where('movement_type', 'transfer')->where('direction', 'in')->keyBy('voucher_id');

        // A transfer relocates lots in receipt order (FIFO, physical age preserved — 12C-1); an OUT
        // depletes in the item's costing order. $lifoOrder chooses newest-first when true.
        $pick = function (?int $g, string $onOrBefore, bool $lifoOrder) use (&$state) {
            $pool = array_filter($state, fn ($s) => $s->remaining > 1e-9
                && ($g === null || $s->godown_id === $g)
                && $s->lot->received_date->toDateString() <= $onOrBefore);
            usort($pool, fn ($a, $b) => $lifoOrder
                ? [$b->lot->received_date, $b->lot->id] <=> [$a->lot->received_date, $a->lot->id]
                : [$a->lot->received_date, $a->lot->id] <=> [$b->lot->received_date, $b->lot->id]);

            return $pool;
        };

        $eventDates = StockEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->where('stock_entries.stock_item_id', $stockItemId)
            ->pluck('vouchers.date', 'stock_entries.id');

        foreach ($events as $e) {
            if ($e->movement_type === 'transfer') {
                if ($e->direction !== 'out') {
                    continue;
                }
                $eventDate = substr((string) $eventDates[$e->id], 0, 10);
                $inLeg = $transferIns->get($e->voucher_id);
                $left = (float) $e->quantity;
                foreach ($pick($e->godown_id !== null ? (int) $e->godown_id : null, $eventDate, false) as $s) {
                    if ($left <= 1e-9) {
                        break;
                    }
                    $take = min($left, $s->remaining);
                    $s->remaining -= $take;
                    $state[] = (object) ['lot' => $s->lot, 'remaining' => $take,
                        'godown_id' => $inLeg?->godown_id !== null ? (int) $inLeg->godown_id : null];
                    $left -= $take;
                }

                continue;
            }

            $eventDate = substr((string) $eventDates[$e->id], 0, 10);
            $left = (float) $e->quantity;
            foreach ($pick($e->godown_id !== null ? (int) $e->godown_id : null, $eventDate, $lifo) as $s) {
                if ($left <= 1e-9) {
                    break;
                }
                $take = min($left, $s->remaining);
                $s->remaining -= $take;
                $left -= $take;
            }
        }

        return collect($state)
            ->filter(fn ($s) => $s->remaining > 1e-9 && ($godownId === null || $s->godown_id === $godownId))
            ->map(fn ($s) => ['lot' => $s->lot, 'remaining' => round($s->remaining, 4), 'godown_id' => $s->godown_id !== null ? (int) $s->godown_id : null])
            ->values()
            ->all();
    }

    /**
     * Phase 13 — the closing stock VALUE of a FIFO/LIFO item as of a date = Σ remaining_qty × rate over
     * its lots (scenario-aware via remainingLotsFor). This is the "specific-lot" valuation the Balance
     * Sheet Stock-in-Hand uses instead of the running average.
     *
     * @return array{qty: float, value: float}
     */
    public function fifoLifoClosing(int $stockItemId, Carbon $asOf): array
    {
        $qty = 0.0;
        $value = 0.0;
        foreach ($this->remainingLotsFor($stockItemId, null, $asOf) as $row) {
            $qty += $row['remaining'];
            $value += $row['remaining'] * ((int) $row['lot']->received_rate_paise) / 100;
        }

        return ['qty' => round($qty, 4), 'value' => round($value, 2)];
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** FIFO-consume remaining qty (provenance): the row's godown first; null godown = all godowns. */
    private function consume(int $stockItemId, ?int $godownId, float $qty, ?string $onOrBefore = null): void
    {
        $lots = StockLot::where('stock_item_id', $stockItemId)
            ->where('remaining_qty', '>', 0)
            ->when($godownId !== null, fn ($q) => $q->where('godown_id', $godownId))
            ->when($onOrBefore !== null, fn ($q) => $q->whereDate('received_date', '<=', $onOrBefore))
            ->tap(fn ($q) => ScenarioContext::applyByVoucher($q, 'stock_lots.voucher_id'))
            ->orderBy('received_date')->orderBy('id')
            ->get();

        $left = $qty;
        foreach ($lots as $lot) {
            if ($left <= 1e-9) {
                break;
            }
            $take = min($left, (float) $lot->remaining_qty);
            $lot->update(['remaining_qty' => round((float) $lot->remaining_qty - $take, 4)]);
            $left -= $take;
        }
    }

    /**
     * Best-effort identification of the counterparty's matching OUT voucher (the 12B cross-company
     * read). Exactly one candidate → recorded; zero or several → null (12C-2 shows UNMATCHED).
     *
     * @return array{0: ?int, 1: ?int} [source_voucher_id, source_cost_paise]
     */
    private function matchSourceVoucher(int $sourceCompanyId, Voucher $voucher, StockEntry $inRow): array
    {
        $counterpartyLedgerId = app(InterCompanyService::class)->reciprocalLedgerId($sourceCompanyId);
        if ($counterpartyLedgerId === null) {
            return [null, null];
        }

        $itemName = StockItem::whereKey($inRow->stock_item_id)->value('name');
        if ($itemName === null) {
            return [null, null];
        }

        $candidates = StockEntry::withoutGlobalScope('company')
            ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
            ->join('stock_items', 'stock_items.id', '=', 'stock_entries.stock_item_id')
            ->where('stock_entries.company_id', $sourceCompanyId)
            ->where('stock_entries.direction', 'out')
            ->whereIn('stock_entries.movement_type', self::SOURCE_OUT_MOVEMENTS)
            ->where('vouchers.party_ledger_id', $counterpartyLedgerId)
            ->where('stock_entries.quantity', $inRow->quantity)
            ->whereDate('vouchers.date', '<=', $voucher->date)
            ->whereNull('vouchers.scenario_id')
            ->whereRaw('LOWER(stock_items.name) = ?', [mb_strtolower($itemName)])
            ->get(['stock_entries.voucher_id', 'stock_entries.rate']);

        if ($candidates->count() !== 1) {
            return [null, null];
        }

        return [(int) $candidates[0]->voucher_id, (int) round(((float) $candidates[0]->rate) * 100)];
    }

    /** Migration-window guard, keyed by database. */
    private function tableExists(): bool
    {
        $db = DB::connection()->getDatabaseName();

        if (! empty(self::$tableSeen[$db])) {
            return true;
        }

        if (Schema::hasTable('stock_lots')) {
            return self::$tableSeen[$db] = true;
        }

        return false;
    }
}
