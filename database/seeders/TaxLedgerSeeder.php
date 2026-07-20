<?php

namespace Database\Seeders;

use App\Models\AccountGroup;
use App\Models\Ledger;
use Illuminate\Database\Seeder;

/**
 * Seeds the reserved duty ledgers under "Duties & Taxes" for both tax regimes.
 *
 * ZeroBook keeps Output (sales) and Input (purchase) tax on separate ledgers so
 * the tax summary can show output tax vs input tax (ITC) unambiguously. Each is
 * tagged with a tax_role (output/input) and a tax_type; the tax engine and the
 * server verifier pick the exact ledger for a given supply without name-matching.
 *
 *   GST (India):  Output/Input × CGST(central) / SGST(state) / IGST(integrated)
 *   VAT (Nepal):  Output VAT / Input VAT (tax_type 'vat') — a single flat rate
 *   TDS (India):  TDS Payable (tax_type 'tds') — tax withheld on behalf of a vendor
 *
 * GST and VAT are mutually exclusive at the company level, but both sets of duty
 * ledgers exist so switching regimes needs no re-seed. All are reserved.
 *
 * TDS Payable is deliberately the ONE duty ledger with a NULL tax_role. It is neither
 * output nor input tax: it is money withheld from a vendor and owed to the Revenue.
 * GstService::taxLedgerMap() keys on a non-null tax_role, so the NULL keeps this ledger
 * completely invisible to the GST and VAT engines — it can never be picked as a tax line
 * on an invoice, and it never appears in either regime's summary.
 */
class TaxLedgerSeeder extends Seeder
{
    /** [name, role, type] */
    private array $ledgers = [
        ['Output CGST', 'output', 'central'],
        ['Output SGST', 'output', 'state'],
        ['Output IGST', 'output', 'integrated'],
        ['Input CGST', 'input', 'central'],
        ['Input SGST', 'input', 'state'],
        ['Input IGST', 'input', 'integrated'],
        // Nepal VAT — a single flat rate, no intra/inter split.
        ['Output VAT', 'output', 'vat'],
        ['Input VAT', 'input', 'vat'],
        // Phase 10A — tax deducted at source, payable to the government.
        ['TDS Payable', null, 'tds'],
    ];

    public function run(): void
    {
        $group = AccountGroup::where('name', 'Duties & Taxes')->firstOrFail();

        foreach ($this->ledgers as [$name, $role, $type]) {
            Ledger::updateOrCreate(
                ['name' => $name],
                [
                    'group_id' => $group->id,
                    'opening_balance' => 0,
                    'opening_balance_type' => null,
                    'is_reserved' => true,
                    'is_pl_account' => false,
                    'tax_role' => $role,
                    'tax_type' => $type,
                    'country' => 'India',
                ],
            );
        }
    }
}
