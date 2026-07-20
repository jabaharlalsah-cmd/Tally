<?php

namespace App\Services\Tds;

/**
 * Phase 10B — the Form 26Q file-format spec, as reference data.
 *
 * Everything here traces to Protean's published spec `form-26q-file-format-current.xlsx`
 * (Form Number 140 (26Q) Version 1.0, for Tax Year 2026-27 onwards). The field layout and
 * the annexures (state codes, section codes, deductor categories, non-deduction flags,
 * minor heads) were machine-extracted from that workbook into two spec-row-cited JSON
 * files under resources/tds-specs/ — the same "page-cited artifact" discipline Phases 9A
 * and 9B used. This class loads them; the exporter and the structural validator both read
 * their rules from here, so nothing about the format is hardcoded twice.
 *
 * The section-code map is the one piece of judgement: ZeroBook's own section codes
 * (`393-194J`, from Phase 10A) are a DIFFERENT system from the 26Q return codes (Annexure
 * 2's four-digit `1027`). The 26Q code is a property of the RETURN FORMAT, not of the
 * deduction, so it lives here in the format layer, keyed by the section's base code.
 */
class Form26qSpec
{
    private ?array $layout = null;

    private ?array $annexures = null;

    public const FILE_TYPE = 'NS1';

    public const FORM_NUMBER = '140';

    public const UPLOAD_TYPE = 'R';

    public const DELIMITER = '^';

    /** The name ZeroBook writes into FH field 10 (Return Preparation Utility). */
    public const RPU_NAME = 'ZeroBook';

    /** No-PAN placeholder (DD field 8) and its higher-rate flag (DD field 32, Annexure 6). */
    public const PAN_NOT_AVAILABLE = 'PANNOTAVBL';

    public const FLAG_HIGHER_RATE_NO_PAN = 'C';

    /**
     * ZeroBook section base code → Form 26Q Annexure-2 return code.
     *
     * Every value is a real Annexure-2 code from the spec; the comment cites the row. A
     * few sections split by deductee type, handled in returnCodeFor(). A user-created
     * section with no entry here fails pre-flight with a clear message — never a guess.
     */
    private const SECTION_CODE_MAP = [
        '194J' => '1027',    // fees for professional services — 393(1) Sl.No 6(iii).D(b)
        '194H' => '1006',    // commission or brokerage (others) — 393(1) Sl.No 1(ii)
        '194I-A' => '1008',  // rent — plant & machinery — 393(1) Sl.No 2(ii).D(a)
        '194I-B' => '1009',  // rent — land & building — 393(1) Sl.No 2(ii).D(b)
        '194I' => '1009',    // legacy rent → land & building
        '194Q' => '1031',    // purchase of goods — 393(1) Sl.No 8(ii)
        '194A' => '1021',    // interest other than securities (non-senior) — 393(1) Sl.No 5(ii).D(b)
        // 194C is deductee-type-dependent — see returnCodeFor().
    ];

    // ---- spec loading ---------------------------------------------------------

    public function layout(): array
    {
        return $this->layout ??= json_decode(
            file_get_contents(resource_path('tds-specs/form-26q-current.json')), true
        );
    }

    public function annexures(): array
    {
        return $this->annexures ??= json_decode(
            file_get_contents(resource_path('tds-specs/form-26q-annexures.json')), true
        );
    }

    /** The field definitions for a record type ('FH','BH','CD','DD'), in spec order. */
    public function fields(string $record): array
    {
        return $this->layout()['records'][$record]['fields'] ?? [];
    }

    /** The mandatory field count for a record — what the FVU's "Record Length" check enforces. */
    public function fieldCount(string $record): int
    {
        return (int) ($this->layout()['records'][$record]['field_count'] ?? 0);
    }

    // ---- annexure lookups -----------------------------------------------------

    public function isValidStateCode(?string $code): bool
    {
        return $code !== null && in_array($code, array_values($this->annexures()['state_codes']), true);
    }

    public function isValidDeductorType(?string $code): bool
    {
        return $code !== null && array_key_exists($code, $this->annexures()['deductor_types']);
    }

    public function isValidSectionReturnCode(?string $code): bool
    {
        if ($code === null) {
            return false;
        }
        foreach ($this->annexures()['section_codes'] as $s) {
            if ($s['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 26Q return code for a ZeroBook section base code + deductee type.
     * Returns null when the section has no mapping (pre-flight then refuses).
     */
    public function returnCodeFor(string $baseCode, ?string $deducteeType): ?string
    {
        if ($baseCode === '194C') {
            // 393(1) Sl.No 6(i).D(a) individual/HUF = 1023; D(b) other = 1024.
            return $deducteeType === 'individual_huf' ? '1023' : '1024';
        }

        return self::SECTION_CODE_MAP[$baseCode] ?? null;
    }

    // ---- identifier validators (spec field rules) -----------------------------

    /** PAN: 5 letters, 4 digits, 1 letter (e.g. ABCDE1234F). */
    public function isValidPan(?string $pan): bool
    {
        return $pan !== null && preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan) === 1;
    }

    /** TAN: 4 letters, 5 digits, 1 letter (e.g. MUMZ12345A). */
    public function isValidTan(?string $tan): bool
    {
        return $tan !== null && preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', $tan) === 1;
    }

    /** BSR code: exactly 7 digits (CD field 15). */
    public function isValidBsr(?string $bsr): bool
    {
        return $bsr !== null && preg_match('/^[0-9]{7}$/', $bsr) === 1;
    }

    /** Bank challan number: 1-5 digits (CD field 17, variable width). */
    public function isValidChallanNumber(?string $n): bool
    {
        return $n !== null && preg_match('/^[0-9]{1,5}$/', $n) === 1;
    }

    /** Pincode: 6 digits. */
    public function isValidPincode(?string $p): bool
    {
        return $p !== null && preg_match('/^[0-9]{6}$/', $p) === 1;
    }

    /** Email: the spec's rule — @ and . present, both flanked by a character, . after @, no ^/space. */
    public function isValidEmail(?string $e): bool
    {
        return $e !== null && preg_match('/^[^^\s@]+@[^^\s@]+\.[^^\s@]+$/', $e) === 1;
    }

    // ---- formatters (spec general notes 4, 5, 10) -----------------------------

    /** Amount → decimal with precision 2 (general note 4), e.g. 2345.00. */
    public static function amount(float $rupees): string
    {
        return number_format($rupees, 2, '.', '');
    }

    /** Rate → decimal with precision 4 (general note 5), e.g. 10.0000. */
    public static function rate(float $r): string
    {
        return number_format($r, 4, '.', '');
    }

    /** Date → ddmmyyyy (general note 8). */
    public static function date(\DateTimeInterface $d): string
    {
        return $d->format('dmY');
    }
}
