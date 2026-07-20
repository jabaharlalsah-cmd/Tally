<?php

namespace App\Support;

/**
 * Phase 9B — serialising the Nepal VAT return.
 *
 * Deliberately NOT the GSTN convention. A GSTR JSON is machine-consumed by the portal's
 * importer, so it is written compact. The IRD taxpayer portal accepts no return file at
 * all — this document exists so a human (or their accountant) can read the boxes and key
 * them into the web form. So it is **pretty-printed**, and the Devanagari labels are
 * written unescaped rather than as \uXXXX sequences.
 */
final class VatReturnExport
{
    public static function encode(array $return): string
    {
        return json_encode(
            $return,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** Write the document to disk, creating the directory if needed. Returns bytes. */
    public static function write(string $path, array $return): int
    {
        $encoded = self::encode($return);

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $encoded);

        return strlen($encoded);
    }

    /** e.g. vat-return-301234567-2082-04.json */
    public static function filename(string $pan, string $period): string
    {
        return sprintf('vat-return-%s-%s.json', $pan, $period);
    }
}
