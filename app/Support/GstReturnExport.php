<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Phase 9A — the two bits of behaviour the GSTR-1 and GSTR-3B export commands (and the
 * Returns screen) share: the default period, and how a returns JSON is serialised.
 */
final class GstReturnExport
{
    /** The last completed month, as `MMYYYY` — the period a filer is normally working on. */
    public static function lastCompletedPeriod(): string
    {
        return Carbon::today()->subMonthNoOverflow()->format('mY');
    }

    /**
     * Serialise a return the way GSTN consumes it: **compact** (no pretty-printing),
     * with slashes and unicode unescaped.
     */
    public static function encode(array $json): string
    {
        return json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Write the encoded return to disk, creating the directory if needed. Returns bytes. */
    public static function write(string $path, array $json): int
    {
        $encoded = self::encode($json);

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $encoded);

        return strlen($encoded);
    }

    /** The conventional filename the offline tool / portal expects to receive. */
    public static function filename(string $returnType, string $gstin, string $period): string
    {
        return sprintf('%s-%s-%s.json', $returnType, $gstin, $period);
    }
}
