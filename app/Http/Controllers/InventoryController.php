<?php

namespace App\Http\Controllers;

use App\Support\Shell;

/**
 * Inventory Info hub (Phase 6A) — Stock Groups / Stock Items / Units / Godowns.
 * Masters only this phase; vouchers and reports arrive in 6B/6C.
 */
class InventoryController extends Controller
{
    public function index()
    {
        return view('inventory.index', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'items' => [
                ['letter' => 'I', 'label' => 'Stock Items', 'desc' => 'Items you buy & sell — Create / Display / Alter', 'kind' => 'nav', 'href' => route('inventory.stock-items')],
                ['letter' => 'G', 'label' => 'Stock Groups', 'desc' => 'Classify stock items (nestable)', 'kind' => 'nav', 'href' => route('inventory.stock-groups')],
                ['letter' => 'U', 'label' => 'Units of Measure', 'desc' => 'Nos / Kg / Box …', 'kind' => 'nav', 'href' => route('inventory.units')],
                ['letter' => 'D', 'label' => 'Godowns', 'desc' => 'Locations where stock is held', 'kind' => 'nav', 'href' => route('inventory.godowns')],
            ],
        ]);
    }

    public function units()
    {
        return view('inventory.units', $this->shell('Inventory · Units'));
    }

    public function stockGroups()
    {
        return view('inventory.stock-groups', $this->shell('Inventory · Stock Groups'));
    }

    public function godowns()
    {
        return view('inventory.godowns', $this->shell('Inventory · Godowns'));
    }

    public function stockItems()
    {
        return view('inventory.stock-items', $this->shell('Inventory · Stock Items'));
    }

    private function shell(string $region): array
    {
        return [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'region' => $region,
        ];
    }
}
