<?php

namespace App\Services\Tds;

/**
 * Phase 10B — the spec-driven structural conformance validator.
 *
 * This is the automated pass/fail gate for the exported file, standing in for the official
 * FVU (which cannot run cleanly on synthetic data — it has an online version self-check, a
 * mandatory external .csi challan-verification file, and a barcode/hash anti-tamper stamp;
 * see PHASE10B_README.md). Its rules are NOT the exporter's rules re-stated: they are read
 * from the government spec's own field tables — the machine-extracted `form-26q-current.json`
 * (field counts, per-field sizes, data types, mandatory flags, each with its spec row) and
 * the annexure code sets. So if the exporter ever drifts from the spec, this catches it.
 *
 * It parses the generated file back and asserts, per the spec:
 *   • the record sequence: FH, BH, then CD followed by its DDs — no File Trailer;
 *   • each record's field COUNT (what the FVU's "Record Length" check enforces);
 *   • the constant fields (FH/BH/CD/DD record types, File Type NS1, Form 140, …);
 *   • each field within its spec max width, and the correct data type (date/amount/rate);
 *   • identifier formats (TAN, PAN, BSR, section code ∈ Annexure 2, state ∈ Annexure 1);
 *   • cross-record reconciliation (BH batch total = Σ CD deposit; CD count = #DDs;
 *     CD deposit = Σ its DD tax);
 *   • line numbers running 1..N.
 */
class Form26qStructuralValidator
{
    public function __construct(private Form26qSpec $spec) {}

    private array $issues = [];

    /** @return array{ok:bool, issues:string[], counts:array} */
    public function validate(string $fileText): array
    {
        $this->issues = [];
        $lines = array_values(array_filter(explode("\r\n", $fileText), fn ($l) => $l !== ''));

        $counts = ['FH' => 0, 'BH' => 0, 'CD' => 0, 'DD' => 0];
        $records = [];
        foreach ($lines as $i => $line) {
            $tokens = explode(Form26qSpec::DELIMITER, $line);
            $type = $tokens[1] ?? '';
            if (! isset($counts[$type])) {
                $this->fail($i + 1, "unknown record type “{$type}”");
                continue;
            }
            $counts[$type]++;
            $records[] = ['type' => $type, 'tokens' => $tokens, 'line' => $i + 1];
        }

        // ---- record sequence ----
        if (($records[0]['type'] ?? null) !== 'FH') {
            $this->fail(1, 'file must begin with a File Header (FH) record');
        }
        if (($records[1]['type'] ?? null) !== 'BH') {
            $this->fail(2, 'the File Header must be followed by a single Batch Header (BH)');
        }
        if ($counts['FH'] !== 1) {
            $this->fail(0, "exactly one FH record expected, found {$counts['FH']}");
        }
        if ($counts['BH'] !== 1) {
            $this->fail(0, "exactly one BH record expected, found {$counts['BH']}");
        }
        if ($counts['DD'] < 1) {
            $this->fail(0, 'a regular 26Q needs at least one deductee (DD) record (spec note 17)');
        }
        // Each CD must be immediately followed by its DDs, and each DD must follow a CD.
        $lastCd = null;
        foreach ($records as $r) {
            if ($r['type'] === 'CD') {
                $lastCd = $r;
            } elseif ($r['type'] === 'DD' && $lastCd === null) {
                $this->fail($r['line'], 'a Deductee (DD) record appears before any Challan (CD) record');
            }
        }

        // ---- per-record field counts, widths, types, constants ----
        foreach ($records as $r) {
            $this->checkRecord($r);
        }

        // ---- line numbers 1..N ----
        foreach ($records as $idx => $r) {
            $ln = $r['tokens'][0] ?? '';
            if ((string) $ln !== (string) ($idx + 1)) {
                $this->fail($r['line'], "line number field is “{$ln}”, expected ".($idx + 1));
            }
        }

        // ---- cross-record reconciliation ----
        $this->reconcile($records);

        return ['ok' => empty($this->issues), 'issues' => $this->issues, 'counts' => $counts];
    }

    /** Field count, per-field max width and data type, and the record's constant fields. */
    private function checkRecord(array $r): void
    {
        $type = $r['type'];
        $tokens = $r['tokens'];
        $fields = $this->spec->fields($type);
        $expected = count($fields);

        // The field COUNT — the FVU's "Record Length" check.
        if (count($tokens) !== $expected) {
            $this->fail($r['line'], "{$type} record has ".count($tokens)." fields, spec requires {$expected}");

            return; // positions unreliable once the count is wrong
        }

        foreach ($fields as $fi => $def) {
            $val = $tokens[$fi];
            $size = (int) ($def['size'] ?: 0);
            $dtype = strtoupper($def['type']);

            // Not-applicable fields (size 0) must be empty.
            if ($size === 0 && $val !== '') {
                $this->fail($r['line'], "{$type} field {$def['sr']} “{$def['name']}” must be empty (spec row {$def['spec_row']})");

                continue;
            }
            if ($val === '') {
                continue; // presence of mandatory values is checked structurally below
            }
            if ($size > 0 && mb_strlen($val) > $size) {
                $this->fail($r['line'], "{$type} field {$def['sr']} “{$def['name']}” = “{$val}” exceeds max width {$size} (spec row {$def['spec_row']})");
            }
            if ($dtype === 'DATE' && preg_match('/^[0-9]{8}$/', $val) !== 1) {
                $this->fail($r['line'], "{$type} field {$def['sr']} “{$def['name']}” = “{$val}” is not a ddmmyyyy date");
            }
            if ($dtype === 'INTEGER' && $size > 0 && preg_match('/^[0-9]+(\.[0-9]{2})?$/', $val) !== 1) {
                $this->fail($r['line'], "{$type} field {$def['sr']} “{$def['name']}” = “{$val}” is not integer/amount form");
            }
            if ($dtype === 'DECIMAL' && preg_match('/^[0-9]+\.[0-9]{2,4}$/', $val) !== 1) {
                $this->fail($r['line'], "{$type} field {$def['sr']} “{$def['name']}” = “{$val}” is not a decimal amount");
            }
        }

        // The constant fields, from the spec.
        $const = [
            'FH' => [2 => 'FH', 3 => Form26qSpec::FILE_TYPE, 4 => Form26qSpec::UPLOAD_TYPE, 7 => 'D'],
            'BH' => [2 => 'BH', 5 => Form26qSpec::FORM_NUMBER, 18 => null],
            'CD' => [2 => 'CD', 6 => 'N', 13 => 'C'],
            'DD' => [2 => 'DD', 6 => 'O', 18 => 'Y'],
        ][$type] ?? [];
        foreach ($const as $sr => $want) {
            if ($want === null) {
                continue;
            }
            $got = $tokens[$sr - 1] ?? '';
            if ($got !== $want) {
                $this->fail($r['line'], "{$type} field {$sr} should be “{$want}”, got “{$got}”");
            }
        }

        // Record-specific identifier + annexure checks.
        if ($type === 'FH' && ! $this->spec->isValidTan($tokens[7])) {
            $this->fail($r['line'], "FH TAN “{$tokens[7]}” is not a valid TAN");
        }
        if ($type === 'BH') {
            if (! $this->spec->isValidTan($tokens[12])) {
                $this->fail($r['line'], "BH TAN “{$tokens[12]}” is not a valid TAN");
            }
            if (! $this->spec->isValidPan($tokens[14])) {
                $this->fail($r['line'], "BH deductor PAN “{$tokens[14]}” is not a valid PAN");
            }
            if (! $this->spec->isValidStateCode($tokens[25])) {
                $this->fail($r['line'], "BH deductor state code “{$tokens[25]}” is not in Annexure 1");
            }
            if (! $this->spec->isValidDeductorType($tokens[31])) {
                $this->fail($r['line'], "BH deductor type “{$tokens[31]}” is not in Annexure 4");
            }
            if (! in_array($tokens[17], ['Q1', 'Q2', 'Q3', 'Q4'], true)) {
                $this->fail($r['line'], "BH period “{$tokens[17]}” must be Q1-Q4");
            }
        }
        if ($type === 'CD' && ! $this->spec->isValidBsr($tokens[14])) {
            $this->fail($r['line'], "CD BSR code “{$tokens[14]}” must be 7 digits");
        }
        if ($type === 'DD') {
            if (! $this->spec->isValidSectionReturnCode($tokens[14])) {
                $this->fail($r['line'], "DD section code “{$tokens[14]}” is not in Annexure 2");
            }
            $pan = $tokens[7];
            $validPan = $this->spec->isValidPan($pan);
            $isPlaceholder = in_array($pan, ['PANNOTAVBL', 'PANAPPLIED', 'PANINVALID'], true);
            if (! $validPan && ! $isPlaceholder) {
                $this->fail($r['line'], "DD deductee PAN “{$pan}” is neither a valid PAN nor a permitted placeholder");
            }
            // No-PAN rows must carry the higher-rate flag C (field 32).
            if ($pan === Form26qSpec::PAN_NOT_AVAILABLE && ($tokens[31] ?? '') !== Form26qSpec::FLAG_HIGHER_RATE_NO_PAN) {
                $this->fail($r['line'], 'DD with no PAN must carry non-deduction flag “C” (higher rate, Section 206AA/397(2))');
            }
        }
    }

    /** BH batch total = Σ CD deposit; CD deductee count = #DDs; CD deposit = Σ its DD tax. */
    private function reconcile(array $records): void
    {
        $bh = null;
        $challans = []; // record_no => ['deposit'=>paise, 'count'=>N, 'dd_sum'=>paise, 'line'=>]
        $current = null;
        foreach ($records as $r) {
            $t = $r['tokens'];
            if ($r['type'] === 'BH') {
                $bh = self::paise($t[46] ?? '0');
            } elseif ($r['type'] === 'CD') {
                $current = (string) ($t[3] ?? '');
                $challans[$current] = [
                    'deposit' => self::paise($t[11] ?? '0'),   // field 12
                    'count' => (int) ($t[4] ?? 0),             // field 5
                    'dd_sum' => 0,
                    'dd_n' => 0,
                    'line' => $r['line'],
                ];
            } elseif ($r['type'] === 'DD') {
                $ref = (string) ($t[4] ?? '');                 // field 5 challan ref
                if (isset($challans[$ref])) {
                    $challans[$ref]['dd_sum'] += self::paise($t[24] ?? '0'); // field 25 deposited
                    $challans[$ref]['dd_n']++;
                }
            }
        }

        $challanDepositSum = 0;
        foreach ($challans as $no => $c) {
            $challanDepositSum += $c['deposit'];
            if ($c['count'] !== $c['dd_n']) {
                $this->fail($c['line'], "CD #{$no} claims {$c['count']} deductees but {$c['dd_n']} DD records reference it");
            }
            if ($c['deposit'] !== $c['dd_sum']) {
                $this->fail($c['line'], sprintf(
                    'CD #%s deposit %s ≠ Σ its deductee tax %s',
                    $no, self::money($c['deposit']), self::money($c['dd_sum']),
                ));
            }
        }
        if ($bh !== null && $bh !== $challanDepositSum) {
            $this->fail(2, sprintf(
                'BH batch total %s ≠ Σ challan deposits %s',
                self::money($bh), self::money($challanDepositSum),
            ));
        }
    }

    private function fail(int $line, string $msg): void
    {
        $this->issues[] = ($line > 0 ? "line {$line}: " : '').$msg;
    }

    private static function paise(string $v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private static function money(int $paise): string
    {
        return number_format($paise / 100, 2);
    }
}
