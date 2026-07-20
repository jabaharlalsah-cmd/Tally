<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;
use Nilambar\NepaliDate\NepaliDate;

/**
 * Phase 9B — Bikram Sambat ⇄ Gregorian, for the Nepal VAT return period.
 *
 * The conversion itself is NOT rolled by hand: it delegates to the maintained
 * `ernilambar/nepali-date` package. This class adds the things a VAT return needs:
 * bounds checking (the package's lookup table stops at BS 2089 and returns an EMPTY
 * ARRAY rather than throwing), the Nepali month names exactly as they are printed on
 * Schedule 10, the fiscal-year ordering, and the Gregorian date range of a BS month.
 *
 * TWO MONTH ORDERINGS, NEVER CONFUSED:
 *   • BS CALENDAR order — Baishakh = 1 … Chaitra = 12. This is what the library uses
 *     and what a ZeroBook period string carries (`2082-04` = Shrawan 2082).
 *   • FISCAL order — Shrawan = 1 … Ashadh = 12. This is the order Schedule 10 prints
 *     its month grid in (साउन … असार), because Nepal's fiscal year runs Shrawan→Ashadh.
 */
final class NepalDate
{
    /** The package's conversion table covers BS 2000–2089. */
    public const MIN_BS_YEAR = 2000;

    public const MAX_BS_YEAR = 2089;

    /** BS calendar order (Baishakh = 1). Spellings taken from Schedule 10, p.70. */
    public const MONTHS_NP = [
        1 => 'बैशाख', 2 => 'जेठ', 3 => 'असार', 4 => 'साउन', 5 => 'भदौ', 6 => 'असोज',
        7 => 'कात्तिक', 8 => 'मंसिर', 9 => 'पुष', 10 => 'माघ', 11 => 'फागुन', 12 => 'चैत्र',
    ];

    public const MONTHS_EN = [
        1 => 'Baishakh', 2 => 'Jestha', 3 => 'Ashadh', 4 => 'Shrawan', 5 => 'Bhadra', 6 => 'Asoj',
        7 => 'Kartik', 8 => 'Mangsir', 9 => 'Poush', 10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra',
    ];

    private static ?NepaliDate $converter = null;

    private static function converter(): NepaliDate
    {
        return self::$converter ??= new NepaliDate();
    }

    // ── period string ───────────────────────────────────────────────────────

    /**
     * A ZeroBook VAT period is `YYYY-MM` in **BS calendar** terms — e.g. `2082-04`
     * is Shrawan 2082. Schedule 10 prescribes no serialized period string (it has a
     * वर्ष box and a ticked month), and the portal's live field is behind taxpayer
     * login, so we never invent a portal format.
     */
    public static function assertPeriod(string $period): array
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m)) {
            throw new InvalidArgumentException(
                "Invalid VAT period “{$period}”. Use BS YYYY-MM, e.g. 2082-04 for Shrawan 2082."
            );
        }
        $year = (int) $m[1];
        $month = (int) $m[2];

        if ($year < self::MIN_BS_YEAR || $year > self::MAX_BS_YEAR) {
            throw new InvalidArgumentException(
                "BS year {$year} is outside the supported range ".self::MIN_BS_YEAR.'–'.self::MAX_BS_YEAR.'.'
            );
        }

        return [$year, $month];
    }

    /** Fiscal-order index of a BS month: Shrawan(4)→1 … Ashadh(3)→12. */
    public static function fiscalMonthIndex(int $bsMonth): int
    {
        return $bsMonth >= 4 ? $bsMonth - 3 : $bsMonth + 9;
    }

    /** The fiscal year a BS month belongs to, e.g. Shrawan 2082 → "2082/83". */
    public static function fiscalYearLabel(int $bsYear, int $bsMonth): string
    {
        $start = $bsMonth >= 4 ? $bsYear : $bsYear - 1;

        return $start.'/'.substr((string) ($start + 1), -2);
    }

    /** "Shrawan 2082 (साउन २०८२)" */
    public static function periodLabel(string $period): string
    {
        [$y, $m] = self::assertPeriod($period);

        return sprintf('%s %d (%s %s)', self::MONTHS_EN[$m], $y, self::MONTHS_NP[$m], self::toDevanagariDigits((string) $y));
    }

    public static function monthNameEn(int $bsMonth): string
    {
        return self::MONTHS_EN[$bsMonth];
    }

    public static function monthNameNp(int $bsMonth): string
    {
        return self::MONTHS_NP[$bsMonth];
    }

    /** Render an ASCII number in Devanagari digits (the form is printed that way). */
    public static function toDevanagariDigits(string $number): string
    {
        return strtr($number, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
            '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
    }

    // ── conversion ──────────────────────────────────────────────────────────

    /** BS (y, m, d) → Gregorian Carbon. Throws if the date is out of the table's range. */
    public static function bsToAd(int $y, int $m, int $d): Carbon
    {
        if ($y < self::MIN_BS_YEAR || $y > self::MAX_BS_YEAR) {
            throw new InvalidArgumentException("BS year {$y} is outside the supported range.");
        }
        $out = self::converter()->convertBsToAd($y, $m, $d);
        // The package signals an unconvertible date with an empty array, not an exception.
        if (! is_array($out) || ! isset($out['year'], $out['month'], $out['day'])) {
            throw new InvalidArgumentException("Cannot convert BS {$y}-{$m}-{$d} to a Gregorian date.");
        }

        return Carbon::create($out['year'], $out['month'], $out['day'])->startOfDay();
    }

    /** Gregorian → BS ['year','month','day']. */
    public static function adToBs(Carbon $date): array
    {
        $out = self::converter()->convertAdToBs($date->year, $date->month, $date->day);
        if (! is_array($out) || ! isset($out['year'], $out['month'], $out['day'])) {
            throw new InvalidArgumentException('Cannot convert '.$date->toDateString().' to a BS date.');
        }

        return $out;
    }

    /**
     * The Gregorian [from, to] span of a BS month — the window a VAT period's vouchers
     * are drawn from. `to` is the day before the next BS month begins, so no day is
     * counted twice and none is missed (BS months are 29–32 days).
     */
    public static function periodRange(string $period): array
    {
        [$y, $m] = self::assertPeriod($period);

        $from = self::bsToAd($y, $m, 1);

        [$ny, $nm] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
        if ($ny > self::MAX_BS_YEAR) {
            throw new InvalidArgumentException("BS {$period} is the last supported month; its end date cannot be resolved.");
        }
        $to = self::bsToAd($ny, $nm, 1)->subDay()->endOfDay();

        return [$from, $to];
    }

    /** The BS month that has most recently finished — the period a filer works on. */
    public static function lastCompletedPeriod(): string
    {
        $bs = self::adToBs(Carbon::today());
        $y = (int) $bs['year'];
        $m = (int) $bs['month'];
        if (--$m === 0) {
            $m = 12;
            $y--;
        }

        return sprintf('%04d-%02d', $y, $m);
    }

    /** The N most recently completed BS periods, newest first: ['2082-04' => 'Shrawan 2082 (…)']. */
    public static function recentPeriods(int $count = 12): array
    {
        [$y, $m] = self::assertPeriod(self::lastCompletedPeriod());
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if ($y < self::MIN_BS_YEAR) {
                break;
            }
            $p = sprintf('%04d-%02d', $y, $m);
            $out[$p] = self::periodLabel($p);
            if (--$m === 0) {
                $m = 12;
                $y--;
            }
        }

        return $out;
    }
}
