<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Services\BalanceService;
use App\Services\StockService;
use App\Support\ScenarioContext;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Stock Item Movement (Phase 6D drill) — the stock_entries behind one item in the
 * period, one row per movement with a running quantity, drillable (Enter) to the
 * voucher. Mirrors the Ledger-Vouchers drill; reuses its keyboard client. Rate/Value
 * is the recorded figure per row (cost for IN / weighted-avg cost for OUT); the
 * authoritative period-end value is shown in the header from StockService.
 */
class StockItemMovement extends Component
{
    use GuardsActiveCompany;

    public int $itemId;
    public string $itemName = '';
    public ?string $from = null;
    public ?string $to = null;

    public function mount(StockItem $stockItem, ?string $from = null, ?string $to = null): void
    {
        $this->itemId = $stockItem->id;
        $this->itemName = $stockItem->name;
        [$f, $t] = app(BalanceService::class)->withinFy($from, $to);
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    public function render()
    {
        [$from, $to] = app(BalanceService::class)->withinFy($this->from, $this->to);
        $stock = app(StockService::class);
        $item = StockItem::with('unit')->find($this->itemId);
        $unit = $item?->unit?->symbol;

        $open = $stock->closingBalance($this->itemId, (clone $from)->subDay());
        $close = $stock->closingBalance($this->itemId, $to);

        $entries = StockEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'stock_entries.voucher_id')
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->leftJoin('godowns', 'godowns.id', '=', 'stock_entries.godown_id')
            ->where('stock_entries.stock_item_id', $this->itemId)
            ->whereBetween('vouchers.date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('vouchers.date')
            ->orderBy('stock_entries.id')
            ->get([
                'stock_entries.voucher_id', 'stock_entries.direction', 'stock_entries.quantity',
                'stock_entries.rate', 'stock_entries.value', 'stock_entries.godown_id',
                'vouchers.date as vdate', 'vouchers.type as vtype', 'vouchers.number as vnumber',
                'godowns.name as godown_name',
            ]);

        $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
        $running = (float) $open['qty'];
        $rows = [];
        foreach ($entries as $e) {
            $running += $e->direction === 'in' ? (float) $e->quantity : -(float) $e->quantity;
            $abbr = strtoupper(Voucher::TYPES[$e->vtype]['abbr'] ?? $e->vtype);
            $rows[] = [
                'voucher_id' => $e->voucher_id,
                'date' => Carbon::parse($e->vdate)->format('d-M-Y'),
                'display_number' => $abbr.'-'.$e->vnumber,
                'godown' => $e->godown_name ?? '—',
                'direction' => $e->direction,
                'qty' => $q($e->quantity).($unit ? ' '.$unit : ''),
                'rate' => BalanceService::money((int) round(((float) $e->rate) * 100)),
                'value' => BalanceService::money((int) round(((float) $e->value) * 100)),
                'running' => $q($running).($unit ? ' '.$unit : ''),
            ];
        }

        return view('livewire.reports.stock-item-movement', [
            'rows' => $rows,
            'itemName' => $this->itemName,
            'openQty' => $q($open['qty']).($unit ? ' '.$unit : ''),
            'openVal' => BalanceService::money((int) round($open['value'] * 100)),
            'closeQty' => $q($close['qty']).($unit ? ' '.$unit : ''),
            'closeVal' => BalanceService::money((int) round($close['value'] * 100)),
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }
}
