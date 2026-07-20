<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Services\BalanceService;
use App\Services\StockService;
use Livewire\Component;

/**
 * Stock Summary (Phase 6D) — quantity + value per Stock Item as of the period end,
 * rolled up through the Stock Group hierarchy (mirrors the Trial Balance's group
 * rollup). Keyboard-navigable via the shared reportScreen: a group expands, an item
 * drills (Enter) to its movement history. Value is the item-level weighted-average
 * closing value (the same figure the P&L Closing Stock and BS Stock-in-Hand use);
 * group rows roll up VALUE only — quantity is shown per item (units don't sum
 * meaningfully across a group).
 */
class StockSummary extends Component
{
    use GuardsActiveCompany;

    public ?string $from = null;
    public ?string $to = null;

    public function mount(): void
    {
        [$f, $t] = app(BalanceService::class)->withinFy(null, null);
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    public function render()
    {
        [$from, $to] = app(BalanceService::class)->withinFy($this->from, $this->to);
        $stock = app(StockService::class);

        $groups = StockGroup::orderBy('name')->get();
        $items = StockItem::with('unit')->orderBy('name')->get();

        $costingLabels = [
            'weighted_average' => 'Weighted Average',
            'fifo' => 'FIFO',
            'lifo' => 'LIFO',
        ];

        // Per-item closing qty/value as of $to.
        $itemData = [];
        foreach ($items as $it) {
            $cb = $stock->closingBalance($it->id, $to);
            $itemData[$it->id] = [
                'id' => $it->id, 'name' => $it->name, 'group_id' => $it->stock_group_id,
                'qty' => $cb['qty'], 'value' => $cb['value'], 'unit' => $it->unit?->symbol,
                'costing' => $costingLabels[$it->costing_method] ?? '',
            ];
        }

        $childrenOf = [];
        foreach ($groups as $g) {
            $childrenOf[$g->parent_id ?? 0][] = $g;
        }
        $itemsOfGroup = [];
        foreach ($itemData as $d) {
            $itemsOfGroup[$d['group_id'] ?? 0][] = $d;
        }

        $rows = [];
        $p = fn ($v) => (int) round($v * 100);

        // Roll up in ROUNDED PAISE (not raw floats) so a group subtotal always equals
        // the sum of the item rows shown beneath it (no ±1-paise display drift).
        $build = function (StockGroup $g, array $ancestors, int $depth) use (&$build, $childrenOf, $itemsOfGroup, &$rows, $p): int {
            $key = 'g'.$g->id;
            $idx = count($rows);
            $rows[] = [
                'key' => $key, 'kind' => 'group', 'depth' => $depth, 'label' => $g->name,
                'qty' => '', 'value' => '', 'value_paise' => 0, 'costing' => '',
                'ledger_id' => null, 'ancestors' => $ancestors, 'collapsible' => false,
            ];
            $child = array_merge($ancestors, [$key]);
            $groupPaise = 0;
            foreach (($childrenOf[$g->id] ?? []) as $cg) {
                $groupPaise += $build($cg, $child, $depth + 1);
            }
            foreach (($itemsOfGroup[$g->id] ?? []) as $it) {
                $row = $this->itemRow($it, $depth + 1, $child, $p);
                $groupPaise += $row['value_paise'];
                $rows[] = $row;
            }
            $rows[$idx]['value'] = BalanceService::money($groupPaise);
            $rows[$idx]['value_paise'] = $groupPaise;
            $rows[$idx]['collapsible'] = ! empty($childrenOf[$g->id]) || ! empty($itemsOfGroup[$g->id]);

            return $groupPaise;
        };

        $grand = 0;
        foreach (($childrenOf[0] ?? []) as $root) {
            $grand += $build($root, [], 0);
        }
        // Items with no Stock Group sit at the root.
        foreach (($itemsOfGroup[0] ?? []) as $it) {
            $row = $this->itemRow($it, 0, [], $p);
            $grand += $row['value_paise'];
            $rows[] = $row;
        }

        return view('livewire.reports.stock-summary', [
            'rows' => $rows,
            'grand' => BalanceService::money($grand),
            'empty' => $items->isEmpty(),
            'from' => $this->from,
            'to' => $this->to,
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
        ]);
    }

    private function itemRow(array $it, int $depth, array $ancestors, callable $p): array
    {
        $q = rtrim(rtrim(number_format((float) $it['qty'], 4, '.', ''), '0'), '.');

        return [
            'key' => 'i'.$it['id'], 'kind' => 'item', 'depth' => $depth, 'label' => $it['name'],
            'qty' => $q.($it['unit'] ? ' '.$it['unit'] : ''),
            'value' => BalanceService::money($p($it['value'])),
            'value_paise' => $p($it['value']),
            'costing' => $it['costing'] ?? '',
            'ledger_id' => $it['id'], // reused by reportScreen as the drill id
            'ancestors' => $ancestors, 'collapsible' => false,
        ];
    }
}
