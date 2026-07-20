<?php

namespace Database\Seeders;

use App\Models\AccountGroup;
use App\Models\Ledger;
use Illuminate\Database\Seeder;

/**
 * Phase 11 — the two reserved ledgers that absorb the exchange-rate timing difference.
 *
 *   Foreign Exchange Gain  → Indirect Incomes   (Cr on a realised/unrealised gain)
 *   Foreign Exchange Loss  → Indirect Expenses  (Dr on a realised/unrealised loss)
 *
 * They are ordinary base-currency nominal ledgers, so they flow into the P&L naturally and
 * are invisible to the GST/VAT/TDS engines (those key on tax_type, which is null here).
 * ForexService identifies them by their reserved names.
 */
class ForexLedgerSeeder extends Seeder
{
    /** [name, group] */
    private array $ledgers = [
        ['Foreign Exchange Gain', 'Indirect Incomes'],
        ['Foreign Exchange Loss', 'Indirect Expenses'],
    ];

    public function run(): void
    {
        foreach ($this->ledgers as [$name, $groupName]) {
            $group = AccountGroup::where('name', $groupName)->firstOrFail();
            Ledger::updateOrCreate(
                ['name' => $name],
                [
                    'group_id' => $group->id,
                    'opening_balance' => 0,
                    'opening_balance_type' => null,
                    'is_reserved' => true,
                    'is_pl_account' => false,
                    'country' => 'India',
                ],
            );
        }
    }
}
