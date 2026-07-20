<?php

namespace App\Support;

/**
 * Money presentation helpers for ZeroBook.
 *
 * The centrepiece is amount-in-words in the Indian numbering system
 * (thousand / lakh / crore), the form printed on Indian invoices — e.g.
 *   1,23,456.75  ->  "Indian Rupees One Lakh Twenty Three Thousand Four
 *                     Hundred Fifty Six and Seventy Five Paise Only"
 */
class Money
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    /** Two-digit and below group (0–99) into words. */
    private static function twoDigits(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        $t = intdiv($n, 10);
        $o = $n % 10;

        return trim(self::TENS[$t].($o ? ' '.self::ONES[$o] : ''));
    }

    /** A group of up to three digits (0–999) into words, with "Hundred". */
    private static function threeDigits(int $n): string
    {
        $h = intdiv($n, 100);
        $rest = $n % 100;
        $parts = [];
        if ($h) {
            $parts[] = self::ONES[$h].' Hundred';
        }
        if ($rest) {
            $parts[] = self::twoDigits($rest);
        }

        return implode(' ', $parts);
    }

    /**
     * Whole-number rupees (>= 0) into Indian-system words (no currency prefix,
     * no "Only"). Groups are crore / lakh / thousand / (hundreds+tens+ones).
     */
    public static function integerToIndianWords(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }

        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $n %= 1000;
        $hundreds = $n; // 0–999

        $parts = [];
        if ($crore) {
            // Crore can itself exceed 99 (e.g. 100 crore), so recurse the group.
            $parts[] = self::integerToIndianWords($crore).' Crore';
        }
        if ($lakh) {
            $parts[] = self::twoDigits($lakh).' Lakh';
        }
        if ($thousand) {
            $parts[] = self::twoDigits($thousand).' Thousand';
        }
        if ($hundreds) {
            $parts[] = self::threeDigits($hundreds);
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Full invoice amount-in-words with rupees + paise and a trailing "Only".
     * Rounds to two decimals (paise). Negative amounts are prefixed "Minus".
     *
     * @param  float  $amount  the rupee amount (e.g. 123456.75)
     * @param  string $currency  spelled-out currency name for the prefix
     */
    public static function inWordsIndian(float $amount, string $currency = 'Indian Rupees'): string
    {
        $sign = $amount < 0 ? 'Minus ' : '';
        // Work in integer paise to avoid float drift, then split.
        $paiseTotal = (int) round(abs($amount) * 100);
        $rupees = intdiv($paiseTotal, 100);
        $paise = $paiseTotal % 100;

        $words = $currency.' '.$sign.self::integerToIndianWords($rupees);
        if ($paise > 0) {
            $words .= ' and '.self::twoDigits($paise).' Paise';
        }

        return $words.' Only';
    }

    /** Indian-grouped number string: 1234567.5 -> "12,34,567.50". */
    public static function indianFormat(float $amount): string
    {
        $neg = $amount < 0 ? '-' : '';
        $paiseTotal = (int) round(abs($amount) * 100);
        $rupees = (string) intdiv($paiseTotal, 100);
        $paise = str_pad((string) ($paiseTotal % 100), 2, '0', STR_PAD_LEFT);

        // Indian grouping: last 3 digits, then groups of 2.
        $len = strlen($rupees);
        if ($len <= 3) {
            $grouped = $rupees;
        } else {
            $last3 = substr($rupees, -3);
            $rest = substr($rupees, 0, $len - 3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $grouped = $rest.','.$last3;
        }

        return $neg.$grouped.'.'.$paise;
    }
}
