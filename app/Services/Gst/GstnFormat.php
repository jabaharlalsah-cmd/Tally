<?php

namespace App\Services\Gst;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Phase 9A — the GSTN wire-format primitives: numeric precision, the return period,
 * the place-of-supply state codes, and the Unit Quantity Codes.
 *
 * Every constant here was read out of the Government's own **Returns Offline Tool
 * v3.2.4** (and the GSTR-3B Excel Utility V5.8), never from recollection — see
 * `_docs/gstn-schemas/` for the derived schema and `_docs/gstn-schemas/reference/`
 * for the tool's own data files. A returns JSON with one field of the wrong shape is
 * rejected by the portal, so the exporter routes EVERY number through these helpers
 * rather than scattering round() calls at different precisions.
 */
class GstnFormat
{
    /** Money in a GSTN return is always 2 decimal places. */
    public static function formatMoney(int|float $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * Quantity (the HSN summary's `qty`). The tool coerces with Number() and the
     * section templates carry up to 4 decimals, so 4dp is the safe wire precision.
     */
    public static function formatQty(int|float $value): float
    {
        return round((float) $value, 4);
    }

    /** A tax rate percentage — 2 decimal places (e.g. 2.5, 18, 0.25). */
    public static function formatRate(int|float $value): float
    {
        return round((float) $value, 2);
    }

    /** Integer paise → rupees, 2dp. The books are exact in paise; the wire is rupees. */
    public static function paiseToMoney(int $paise): float
    {
        return round($paise / 100, 2);
    }

    // ---- period ------------------------------------------------------------

    /** A GSTN return period is `MMYYYY` — e.g. April 2026 is "042026". */
    public static function assertPeriod(string $period): string
    {
        if (! preg_match('/^(0[1-9]|1[0-2])\d{4}$/', $period)) {
            throw new InvalidArgumentException(
                "Invalid return period “{$period}”. Use MMYYYY, e.g. 042026 for April 2026."
            );
        }

        return $period;
    }

    /** `MMYYYY` → `YYYYMM`, the form the tool sorts/compares periods in. */
    public static function periodToYyyymm(string $period): string
    {
        self::assertPeriod($period);

        return substr($period, 2, 4).substr($period, 0, 2);
    }

    /** The first and last calendar day of a `MMYYYY` period. */
    public static function periodRange(string $period): array
    {
        self::assertPeriod($period);
        $from = Carbon::create((int) substr($period, 2, 4), (int) substr($period, 0, 2), 1)->startOfDay();

        return [$from, (clone $from)->endOfMonth()->endOfDay()];
    }

    /** Human label, e.g. "April 2026". */
    public static function periodLabel(string $period): string
    {
        [$from] = self::periodRange($period);

        return $from->format('F Y');
    }

    /** A date on the wire is `DD-MM-YYYY`. */
    public static function formatDate(Carbon $date): string
    {
        return $date->format('d-m-Y');
    }

    // ---- B2CL threshold ----------------------------------------------------

    /**
     * The B2CL invoice-value threshold. It **changed with the Aug-2024 return
     * period**: ₹2,50,000 before, ₹1,00,000 from 082024 onward.
     *
     * Source: the tool's `utility/constants.js` (`B2CL_MIN_VAL = 100000`,
     * `B2CL_MIN_VAL_STR_PRD = '082024'`) and `returnStructure.js` / `offline.js`,
     * which drop a row when `value <= limit` — so a valid B2CL invoice must be
     * **strictly greater than** the limit.
     */
    public static function b2clThreshold(string $period): float
    {
        return self::periodToYyyymm($period) < '202408' ? 250000.0 : 100000.0;
    }

    /** Is this invoice value large enough to be a B2CL invoice for the period? */
    public static function exceedsB2clThreshold(float $value, string $period): bool
    {
        return $value > self::b2clThreshold($period);
    }

    // ---- HSN bifurcation ---------------------------------------------------

    /** From the 052025 return period, `hsn` splits into `hsn_b2b` + `hsn_b2c`. */
    public const HSN_BIFURCATION_START_FP = '052025';

    public static function hsnIsBifurcated(string $period): bool
    {
        return self::periodToYyyymm($period) >= self::periodToYyyymm(self::HSN_BIFURCATION_START_FP);
    }

    // ---- place of supply ---------------------------------------------------

    /**
     * The GSTN state (place-of-supply) codes, verbatim from the tool's
     * `public/data/state.json`. Note there is NO code 28 — 27 Maharashtra jumps
     * straight to 29 Karnataka — and 97 is "Other Territory".
     */
    public const STATE_CODES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab',
        '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi',
        '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram',
        '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
        '24' => 'Gujarat', '25' => 'Daman and Diu',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra',
        '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala',
        '33' => 'Tamil Nadu', '34' => 'Pondicherry', '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh', '97' => 'Other Territory',
    ];

    /** A GSTIN's first two characters ARE its state code — the most reliable POS. */
    public static function posFromGstin(?string $gstin): ?string
    {
        $gstin = trim((string) $gstin);

        return strlen($gstin) >= 2 && ctype_digit(substr($gstin, 0, 2)) ? substr($gstin, 0, 2) : null;
    }

    /** State name → 2-char POS code (case/space insensitive). Null when unknown. */
    public static function posFromStateName(?string $state): ?string
    {
        $needle = self::normalise($state);
        if ($needle === '') {
            return null;
        }
        foreach (self::STATE_CODES as $code => $name) {
            if (self::normalise($name) === $needle) {
                return $code;
            }
        }

        return null;
    }

    /** Place of supply for a party: its GSTIN prefix if registered, else its state. */
    public static function placeOfSupply(?string $gstin, ?string $state, ?string $fallbackState = null): ?string
    {
        return self::posFromGstin($gstin)
            ?? self::posFromStateName($state)
            ?? self::posFromStateName($fallbackState);
    }

    private static function normalise(?string $s): string
    {
        return preg_replace('/[^a-z]/', '', strtolower((string) $s)) ?? '';
    }

    /** Validate a GSTIN against the tool's own regex (utility/common.js, VBA). */
    public static function isValidGstin(?string $gstin): bool
    {
        return (bool) preg_match('/^[0-3][0-9][A-Z]{5}[0-9]{4}[A-Z][0-9][Z][0-9A-Z]$/', (string) $gstin);
    }

    // ---- unit quantity codes ----------------------------------------------

    /** The 44 official UQCs. The wire carries the SHORT code only (e.g. "NOS"). */
    public const UQC_CODES = [
        'BAG', 'BAL', 'BDL', 'BKL', 'BOU', 'BOX', 'BTL', 'BUN', 'CAN', 'CBM', 'CCM',
        'CMS', 'CTN', 'DOZ', 'DRM', 'GGK', 'GMS', 'GRS', 'GYD', 'KGS', 'KLR', 'KME',
        'LTR', 'MLT', 'MTR', 'MTS', 'NOS', 'PAC', 'PCS', 'PRS', 'QTL', 'ROL', 'SET',
        'SQF', 'SQM', 'SQY', 'TBS', 'TGM', 'THD', 'TON', 'TUB', 'UGS', 'UNT', 'YDS', 'OTH',
    ];

    /** Common ZeroBook unit symbols → the official UQC. Anything unknown → OTH. */
    private const UQC_ALIASES = [
        'NO' => 'NOS', 'NOS' => 'NOS', 'NUM' => 'NOS', 'NUMBER' => 'NOS', 'NUMBERS' => 'NOS', 'UNIT' => 'UNT',
        'PC' => 'PCS', 'PCS' => 'PCS', 'PIECE' => 'PCS', 'PIECES' => 'PCS',
        'KG' => 'KGS', 'KGS' => 'KGS', 'KILOGRAM' => 'KGS', 'KILOGRAMS' => 'KGS',
        'GM' => 'GMS', 'GMS' => 'GMS', 'GRAM' => 'GMS', 'GRAMS' => 'GMS',
        'L' => 'LTR', 'LT' => 'LTR', 'LTR' => 'LTR', 'LITRE' => 'LTR', 'LITER' => 'LTR', 'LITRES' => 'LTR',
        'ML' => 'MLT', 'MLT' => 'MLT',
        'M' => 'MTR', 'MTR' => 'MTR', 'METER' => 'MTR', 'METRE' => 'MTR', 'METERS' => 'MTR',
        'CM' => 'CMS', 'CMS' => 'CMS', 'KM' => 'KME',
        'BOX' => 'BOX', 'BAG' => 'BAG', 'BAGS' => 'BAG', 'BTL' => 'BTL', 'BOTTLE' => 'BTL',
        'CAN' => 'CAN', 'CTN' => 'CTN', 'CARTON' => 'CTN', 'DOZ' => 'DOZ', 'DOZEN' => 'DOZ',
        'DRM' => 'DRM', 'DRUM' => 'DRM', 'PAC' => 'PAC', 'PACK' => 'PAC', 'PKT' => 'PAC',
        'PAIR' => 'PRS', 'PRS' => 'PRS', 'ROL' => 'ROL', 'ROLL' => 'ROL',
        'SET' => 'SET', 'SETS' => 'SET', 'TON' => 'TON', 'TONNE' => 'TON', 'TONNES' => 'TON',
        'MT' => 'MTS', 'MTS' => 'MTS', 'QTL' => 'QTL', 'QUINTAL' => 'QTL',
        'SQF' => 'SQF', 'SQFT' => 'SQF', 'SQM' => 'SQM', 'SQY' => 'SQY', 'YD' => 'YDS', 'YDS' => 'YDS',
        'THD' => 'THD', 'TUB' => 'TUB', 'TBS' => 'TBS', 'UNT' => 'UNT', 'UNITS' => 'UNT',
    ];

    /** Map a ZeroBook Unit symbol to an official UQC; unknown units fall back to OTH. */
    public static function uqc(?string $unitSymbol): string
    {
        $key = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $unitSymbol) ?? '');
        if ($key === '') {
            return 'OTH';
        }
        if (isset(self::UQC_ALIASES[$key])) {
            return self::UQC_ALIASES[$key];
        }

        return in_array($key, self::UQC_CODES, true) ? $key : 'OTH';
    }

    // ---- misc wire conventions --------------------------------------------

    /**
     * The item-line serial the tool writes inside `itms[]`: `rate * 100 + 1`
     * (an 18% line is `1801`, a 5% line is `501`). Not a running counter.
     */
    public static function itemNum(float $rate): int
    {
        return (int) round($rate * 100) + 1;
    }

    /** Nature-of-Document codes for `doc_issue.doc_det[].doc_num` (1-based). */
    public const DOC_NATURE = [
        1 => 'Invoices for outward supply',
        2 => 'Invoices for inward supply from unregistered person',
        3 => 'Revised Invoice',
        4 => 'Debit Note',
        5 => 'Credit Note',
        6 => 'Receipt Voucher',
        7 => 'Payment Voucher',
        8 => 'Refund Voucher',
        9 => 'Delivery Challan for job work',
        10 => 'Delivery Challan for supply on approval',
        11 => 'Delivery Challan in case of liquid gas',
        12 => 'Delivery Challan in case other than by way of supply (excluding at S no. 9 to 11)',
    ];
}
