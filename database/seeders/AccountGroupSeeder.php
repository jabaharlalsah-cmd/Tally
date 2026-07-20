<?php

namespace Database\Seeders;

use App\Models\AccountGroup;
use Illuminate\Database\Seeder;

/**
 * Seeds Tally's standard predefined chart-of-accounts groups:
 * 15 primary groups + 13 sub-groups = 28, all marked reserved (non-deletable).
 */
class AccountGroupSeeder extends Seeder
{
    /** [name, nature] */
    private array $primaries = [
        ['Capital Account', 'Liabilities'],
        ['Current Assets', 'Assets'],
        ['Current Liabilities', 'Liabilities'],
        ['Direct Expenses', 'Expenses'],
        ['Direct Incomes', 'Income'],
        ['Fixed Assets', 'Assets'],
        ['Indirect Expenses', 'Expenses'],
        ['Indirect Incomes', 'Income'],
        ['Investments', 'Assets'],
        ['Loans (Liability)', 'Liabilities'],
        ['Misc. Expenses (ASSET)', 'Assets'],
        ['Purchase Accounts', 'Expenses'],
        ['Sales Accounts', 'Income'],
        ['Suspense A/c', 'Liabilities'],
        ['Branch / Divisions', 'Liabilities'],
    ];

    /** [name, parent name] — nature inherited from parent */
    private array $subs = [
        ['Bank Accounts', 'Current Assets'],
        ['Cash-in-Hand', 'Current Assets'],
        ['Deposits (Asset)', 'Current Assets'],
        ['Loans & Advances (Asset)', 'Current Assets'],
        ['Stock-in-Hand', 'Current Assets'],
        ['Sundry Debtors', 'Current Assets'],
        ['Duties & Taxes', 'Current Liabilities'],
        ['Provisions', 'Current Liabilities'],
        ['Sundry Creditors', 'Current Liabilities'],
        ['Reserves & Surplus', 'Capital Account'],
        ['Bank OD A/c', 'Loans (Liability)'],
        ['Secured Loans', 'Loans (Liability)'],
        ['Unsecured Loans', 'Loans (Liability)'],
    ];

    public function run(): void
    {
        $order = 0;

        foreach ($this->primaries as [$name, $nature]) {
            AccountGroup::updateOrCreate(
                ['name' => $name],
                [
                    'parent_id' => null,
                    'nature' => $nature,
                    'is_primary' => true,
                    'is_reserved' => true,
                    'sort_order' => $order += 10,
                ],
            );
        }

        foreach ($this->subs as [$name, $parentName]) {
            $parent = AccountGroup::where('name', $parentName)->firstOrFail();
            AccountGroup::updateOrCreate(
                ['name' => $name],
                [
                    'parent_id' => $parent->id,
                    'nature' => $parent->nature,
                    'is_primary' => false,
                    'is_reserved' => true,
                    'sort_order' => $order += 10,
                ],
            );
        }
    }
}
