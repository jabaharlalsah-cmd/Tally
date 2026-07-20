<?php

namespace Database\Seeders;

use App\Models\AccountGroup;
use App\Models\Ledger;
use Illuminate\Database\Seeder;

/**
 * Phase 8A — the return nominal ledgers Tally convention uses for Debit/Credit Notes.
 *
 *   • "Sales Return"    under Sales Accounts (Income)   — debited by a Credit Note.
 *   • "Purchase Return" under Purchase Accounts (Expenses) — credited by a Debit Note.
 *
 * A company can post Notes to the plain Sales/Purchase ledgers instead; these are the
 * conventional defaults, seeded idempotently (updateOrCreate on name). No tax rate is
 * set — item-invoice Notes take their rate from the stock item; accounting-mode Notes
 * take it from whichever nominal ledger the user picks.
 */
class ReturnLedgerSeeder extends Seeder
{
    public function run(): void
    {
        $salesGroup = AccountGroup::where('name', 'Sales Accounts')->value('id');
        if ($salesGroup) {
            Ledger::updateOrCreate(
                ['name' => 'Sales Return'],
                ['group_id' => $salesGroup, 'country' => 'India', 'is_reserved' => false, 'is_pl_account' => false],
            );
        }

        $purchaseGroup = AccountGroup::where('name', 'Purchase Accounts')->value('id');
        if ($purchaseGroup) {
            Ledger::updateOrCreate(
                ['name' => 'Purchase Return'],
                ['group_id' => $purchaseGroup, 'country' => 'India', 'is_reserved' => false, 'is_pl_account' => false],
            );
        }
    }
}
