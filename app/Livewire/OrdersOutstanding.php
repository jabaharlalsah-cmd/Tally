<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Voucher;
use App\Services\OrderService;
use Livewire\Component;

/**
 * Phase 8B — the Sales / Purchase Orders Outstanding report. Lists every order that
 * still has undelivered quantity, its open lines (ordered / delivered / pending), and
 * the pending value. Drillable to the order voucher. A fully-delivered order drops off.
 *
 * These orders carry ZERO accounting weight — this report reads the reconciliation
 * cache (order_lines.delivered_qty), never the ledger.
 */
class OrdersOutstanding extends Component
{
    use GuardsActiveCompany;

    public string $scope = 'sales'; // sales | purchase

    public function mount(?string $scope = null): void
    {
        $this->scope = in_array($scope, ['sales', 'purchase'], true) ? $scope : 'sales';
    }

    private function orderType(): string
    {
        return $this->scope === 'purchase' ? 'purchase_order' : 'sales_order';
    }

    public function render()
    {
        $rows = app(OrderService::class)->outstanding([$this->orderType()]);

        return view('livewire.orders-outstanding', [
            'rows' => $rows,
            'totalValue' => array_sum(array_column($rows, 'pending_value')),
            'heading' => $this->scope === 'purchase' ? 'Purchase Orders Outstanding' : 'Sales Orders Outstanding',
            'partyLabel' => $this->scope === 'purchase' ? 'Supplier' : 'Customer',
            'otherScope' => $this->scope === 'purchase' ? 'sales' : 'purchase',
            'otherLabel' => $this->scope === 'purchase' ? 'Sales Orders' : 'Purchase Orders',
            'typeLabel' => Voucher::TYPES[$this->orderType()]['label'],
        ]);
    }
}
