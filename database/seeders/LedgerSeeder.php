<?php

namespace Database\Seeders;

use App\Models\AccountGroup;
use App\Models\Ledger;
use Illuminate\Database\Seeder;

/**
 * Seeds the two ledgers Tally auto-creates in every new company:
 *   - Cash               (under Cash-in-Hand)
 *   - Profit & Loss A/c  (special, under "Primary" — group_id null)
 * Both are reserved (non-deletable).
 */
class LedgerSeeder extends Seeder
{
    public function run(): void
    {
        $cashInHand = AccountGroup::where('name', 'Cash-in-Hand')->firstOrFail();

        Ledger::updateOrCreate(
            ['name' => 'Cash'],
            [
                'group_id' => $cashInHand->id,
                'opening_balance' => 0,
                'opening_balance_type' => null,
                'is_reserved' => true,
                'is_pl_account' => false,
            ],
        );

        Ledger::updateOrCreate(
            ['name' => 'Profit & Loss A/c'],
            [
                'group_id' => null, // special: sits under "Primary" as in Tally
                'opening_balance' => 0,
                'opening_balance_type' => null,
                'is_reserved' => true,
                'is_pl_account' => true,
            ],
        );
    }
}
