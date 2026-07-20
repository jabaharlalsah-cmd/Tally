<?php

namespace Database\Seeders;

use App\Models\TdsSection;
use Illuminate\Database\Seeder;

/**
 * Phase 10A — the TDS section catalog.
 *
 * ┌───────────────────────────────────────────────────────────────────────────┐
 * │  A CHARTERED ACCOUNTANT MUST VERIFY THESE RATES AND THRESHOLDS AGAINST    │
 * │  THE CURRENT FINANCE ACT BEFORE THIS IS USED ON A REAL BOOK.              │
 * │  They change annually. They are seeded here as reasonable DEFAULTS only.  │
 * │  Every one of them is editable from the TDS Sections master screen, and   │
 * │  nothing in the engine hardcodes a rate or a threshold.                   │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * THE 2026 REGIME CHANGE. On 1 April 2026 the Income Tax Act 2025 replaced the old
 * 194-series with a consolidated Section 393 for non-salary payments (and Section 392
 * for salary, which ZeroBook does not yet handle). Rates and thresholds largely carried
 * over; the SECTION CODE printed on a return did not. A live book therefore contains
 * both: vouchers dated in FY 2025-26 and earlier belong to 194J, vouchers from FY
 * 2026-27 to 393-194J.
 *
 * So each row is effective-dated by FISCAL-YEAR START (2026 => FY 2026-27):
 *
 *   • the old 194-series rows end at effective_to = 2025 (last valid FY 2025-26);
 *   • the new 393-series rows begin at effective_from = 2026 and never expire.
 *
 * TdsService::sections() shows only what is in force for the current fiscal year, and
 * refuses to deduct under a section that is not in force for the VOUCHER's fiscal year.
 * That is what keeps a historical book correct and a current book compliant.
 *
 * Two rows share a code but not a date range (194I-B): the section did not change, only
 * its threshold did — an annual ₹2,40,000 became a per-month ₹50,000 from FY 2025-26.
 * unique(code, effective_from) exists for exactly this.
 *
 * `threshold_period` and `deduct_basis` carry the two structural quirks:
 *   • 194I aggregates its threshold PER MONTH, not per year.
 *   • 194Q deducts only on the value in EXCESS of ₹50 lakh, not on the whole aggregate.
 */
class TdsSectionSeeder extends Seeder
{
    /**
     * Columns, in order:
     *   code, label, rate, rate_company, no_pan_rate, threshold_single, threshold_annual,
     *   threshold_period, deduct_basis, effective_from, effective_to
     *
     * `no_pan_rate` is the Section 206AA floor. NULL means the statutory 20%. The proviso
     * to 206AA(1) caps it at 5% for 194Q (and 194O), so that exception lives in the DATA.
     */

    /**
     * The old 194-series, in force through FY 2025-26 (effective_to = 2025).
     * Kept in the catalog so a historical voucher still books — and reports — correctly.
     */
    private const OLD_SERIES = [
        ['194A',   'Interest other than on securities',           10.00, null, null, null,  50000,   'annual', 'aggregate', 2000, 2025],
        ['194C',   'Payments to contractors',                      1.00, 2.00, null, 30000, 100000,  'annual', 'aggregate', 2000, 2025],
        ['194H',   'Commission or brokerage',                      2.00, null, null, null,  20000,   'annual', 'aggregate', 2000, 2025],
        ['194J',   'Fees for professional or technical services', 10.00, null, null, null,  50000,   'annual', 'aggregate', 2000, 2025],
        // Rent, land & building — the ANNUAL-threshold era, closed at FY 2024-25.
        ['194I-B', 'Rent — land, building or furniture',          10.00, null, null, null,  240000,  'annual', 'aggregate', 2000, 2024],
        ['194Q',   'Purchase of goods',                            0.10, null, 5.00, null,  5000000, 'annual', 'excess',    2021, 2025],
    ];

    /**
     * The Finance Act 2025 re-thresholded 194I to a per-MONTH ₹50,000 from FY 2025-26.
     * Same code, new date range, new threshold_period — no code change anywhere.
     */
    private const RETHRESHOLDED = [
        ['194I-A', 'Rent — plant, machinery or equipment',         2.00, null, null, null, 50000, 'monthly', 'aggregate', 2025, 2025],
        ['194I-B', 'Rent — land, building or furniture',          10.00, null, null, null, 50000, 'monthly', 'aggregate', 2025, 2025],
    ];

    /**
     * Section 393 of the Income Tax Act 2025 — in force from FY 2026-27 (effective_from
     * = 2026), still current. The sub-provision suffix keeps the mapping to the section
     * everyone still names in conversation.
     */
    private const NEW_SERIES = [
        ['393-194A',   'Interest other than on securities',           10.00, null, null, null,  50000,   'annual',  'aggregate', 2026, null],
        ['393-194C',   'Payments to contractors',                      1.00, 2.00, null, 30000, 100000,  'annual',  'aggregate', 2026, null],
        ['393-194H',   'Commission or brokerage',                      2.00, null, null, null,  20000,   'annual',  'aggregate', 2026, null],
        ['393-194I-A', 'Rent — plant, machinery or equipment',         2.00, null, null, null,  50000,   'monthly', 'aggregate', 2026, null],
        ['393-194I-B', 'Rent — land, building or furniture',          10.00, null, null, null,  50000,   'monthly', 'aggregate', 2026, null],
        ['393-194J',   'Fees for professional or technical services', 10.00, null, null, null,  50000,   'annual',  'aggregate', 2026, null],
        ['393-194Q',   'Purchase of goods',                            0.10, null, 5.00, null,  5000000, 'annual',  'excess',    2026, null],
    ];

    private const NOTES = [
        '194A' => 'Bank/post-office and senior-citizen payees have different thresholds — add separate sections if you need them.',
        '194C' => 'A single bill above the single threshold is deducted on its own; once the annual aggregate is also crossed, tax falls on the whole aggregate.',
        '194H' => 'Verify the current rate — this was reduced from 5% to 2% with effect from 1 October 2024.',
        '194J' => 'Technical services and call-centre payments are deducted at 2%, not 10% — add a separate section for those.',
        '194I-A' => 'Threshold is per MONTH (or part of a month), not per year.',
        '194I-B' => 'Threshold is per MONTH (or part of a month) from FY 2025-26; it was ₹2,40,000 per year before that.',
        '194Q' => 'Deducted only on the value EXCEEDING the aggregate threshold, and only by buyers above the turnover limit. Without a PAN the 206AA floor is 5%, not 20%.',
    ];

    public function run(): void
    {
        foreach ([...self::OLD_SERIES, ...self::RETHRESHOLDED, ...self::NEW_SERIES] as $row) {
            [$code, $label, $rate, $rateCompany, $noPanRate, $single, $annual, $period, $basis, $from, $to] = $row;

            TdsSection::updateOrCreate(
                ['code' => $code, 'effective_from' => $from],
                [
                    'label' => $label,
                    'rate' => $rate,
                    'rate_company' => $rateCompany,
                    'no_pan_rate' => $noPanRate,
                    'threshold_single' => $single,
                    'threshold_annual' => $annual,
                    'threshold_period' => $period,
                    'deduct_basis' => $basis,
                    'effective_to' => $to,
                    'notes' => self::NOTES[TdsSection::baseCode($code)] ?? null,
                ],
            );
        }
    }
}
