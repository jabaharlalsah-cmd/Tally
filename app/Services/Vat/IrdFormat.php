<?php

namespace App\Services\Vat;

/**
 * Phase 9B — the IRD wire-format primitives.
 *
 * Nepal's VAT return (अनुसूची-१०) is denominated in **whole rupees**, not paisa, which
 * is why the GSTN 2-decimal helpers are NOT reused here. The rule is printed on the
 * form itself, immediately above the tax-computation grid (page 71):
 *
 *   "यदि यस अवधिमा कारोवार नगरेको भए तलको महलमा शुन्य राखेर विवरणमा हस्ताक्षर गर्नुहोस् ।
 *    रु.१ भन्दा घटि भएमा पैसालाई रु.१ मा मिलान गरी विवरण भर्नुहोला ।"
 *
 *   "If no transaction took place in this period, put zero in the columns below and
 *    sign the return. If [an amount] is less than Re. 1, adjust the paisa to Re. 1
 *    when filling the return."
 *
 * So: a genuinely zero amount stays 0; any other amount is reported in whole rupees,
 * and one that would round away to nothing is reported as 1 (or −1 when negative)
 * rather than silently vanishing from the return.
 */
final class IrdFormat
{
    /** Integer paise (ZeroBook's exact internal unit) → the whole rupees the form wants. */
    public static function rupeesFromPaise(int $paise): int
    {
        if ($paise === 0) {
            return 0;
        }
        $rupees = (int) round($paise / 100);

        // A non-zero amount below Re. 1 is carried into the return as Re. 1.
        if ($rupees === 0) {
            return $paise > 0 ? 1 : -1;
        }

        return $rupees;
    }

    /** Rupees (float) → whole rupees, same rule. */
    public static function rupees(float $amount): int
    {
        return self::rupeesFromPaise((int) round($amount * 100));
    }

    /** The form prints (+ वा (—)) beside boxes 5 and 7. */
    public static function sign(int $amount): string
    {
        return $amount < 0 ? '—' : '+';
    }

    /** A count box (box 11) is a plain non-negative integer. */
    public static function count(int $n): int
    {
        return max(0, $n);
    }
}
