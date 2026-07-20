<?php

namespace App\Services\Tds;

use App\Models\CompanyFeature;
use App\Models\TdsChallan;
use App\Models\TdsDeduction;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10B — the Form 26Q quarterly return-file exporter.
 *
 * READ-ONLY over Phase 10A. It assembles the caret-delimited `.txt` a CA uploads (after
 * running it through the official FVU with their real .csi) from three existing sources:
 *
 *   • the deductor's filing identity        — company_features (Phase 10B additions)
 *   • the deductions of the quarter          — tds_deductions (Phase 10A)
 *   • the real bank challan identifiers       — tds_challans   (Phase 10B)
 *
 * Every field position traces to Protean's published spec via {@see Form26qSpec}. Four
 * record types, in sequence: FH (one), BH (one), then for each challan a CD followed by
 * its DDs. There is no File Trailer in this format.
 *
 * THE CHALLAN ↔ DEDUCTION MAPPING. A 26Q challan (CD) carries the deductions (DDs) it
 * discharged; the sum of a challan's deductee TDS must equal the challan amount. Phase 10A
 * already discharges deductions FIFO; this exporter makes that mapping explicit at
 * WHOLE-DEDUCTION granularity — a deductee row belongs to exactly one challan. Pre-flight
 * refuses if the quarter's challans don't exactly cover its deductions.
 */
class Form26qExporter
{
    public function __construct(private Form26qSpec $spec) {}

    /** Human-readable pre-flight failures (row-named), collected before any file is emitted. */
    private array $errors = [];

    /**
     * The full 26Q file text for a quarter, ready to write to disk. Reads only.
     * Throws a Form26qException carrying the row-named pre-flight errors if the data is
     * not filable — so the user fixes it in ZeroBook, not by fighting the FVU.
     */
    public function record(int $fyStart, int $quarter): string
    {
        $this->errors = [];
        $data = $this->gather($fyStart, $quarter);

        if ($this->errors) {
            throw new Form26qException($this->errors);
        }

        $lines = [];
        $lineNo = 1;

        $lines[] = $this->buildFileHeader($lineNo++, $data);
        $lines[] = $this->buildBatchHeader($lineNo++, $data);

        $ddNo = 0; // running deductee-detail record number across the batch (DD field 4)
        foreach ($data['challans'] as $ch) {
            $lines[] = $this->buildChallan($lineNo++, $ch);
            foreach ($ch['deductions'] as $dd) {
                $lines[] = $this->buildDeductee($lineNo++, ++$ddNo, $ch, $dd);
            }
        }

        // ASCII, each record ending with CRLF (spec general note 2, hex 0D 0A).
        return implode("\r\n", $lines)."\r\n";
    }

    /** The pre-flight errors from the last record() call (empty if it succeeded). */
    public function errors(): array
    {
        return $this->errors;
    }

    // ---- gathering + pre-flight ----------------------------------------------

    /**
     * Assemble everything the builders need, validating as we go. Populates $this->errors
     * with row-named problems; the caller checks before emitting.
     */
    private function gather(int $fyStart, int $quarter): array
    {
        [$from, $to] = self::quarterRange($fyStart, $quarter);

        if (\App\Models\Voucher::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNotNull('scenario_id')->exists()) {
            $this->errors[] = 'Scenario vouchers exist in this period. Return files must use actuals only.';

            return [];
        }

        $profile = CompanyFeature::current()->deductorProfile();
        $this->validateDeductorProfile($profile);

        // Deductions of the quarter that actually withheld tax. Below-threshold zero-TDS
        // rows belong to NIL-challan / non-deduction-flag reporting, which is deferred
        // (see README) — they carry no challan and would need Annexure-6 flags.
        $deductions = TdsDeduction::query()
            ->join('vouchers', 'vouchers.id', '=', 'tds_deductions.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->leftJoin('ledgers', 'ledgers.id', '=', 'tds_deductions.deductee_ledger_id')
            ->leftJoin('tds_sections', 'tds_sections.id', '=', 'tds_deductions.tds_section_id')
            ->where('tds_deductions.fy_start', $fyStart)
            ->where('vouchers.date', '>=', $from->toDateString())
            ->where('vouchers.date', '<=', $to->toDateString())
            ->where('tds_deductions.deducted_amount', '>', 0)
            ->orderBy('vouchers.date')->orderBy('tds_deductions.id')
            ->get([
                'tds_deductions.id',
                'tds_deductions.payment_amount',
                'tds_deductions.deducted_amount',
                'tds_deductions.rate',
                'vouchers.date as v_date',
                'ledgers.name as deductee_name',
                'ledgers.deductee_pan as deductee_pan',
                'ledgers.deductee_type as deductee_type',
                'tds_sections.code as section_code',
            ]);

        if ($deductions->isEmpty()) {
            $this->errors[] = sprintf(
                'No TDS was deducted in FY %s Q%d. A regular 26Q needs at least one deductee record (spec note 17).',
                Voucher::statutoryFyLabel($fyStart), $quarter,
            );
        }

        // Resolve each deduction's 26Q section code + PAN handling now, so per-row errors
        // are reported before anything is built.
        $rows = [];
        foreach ($deductions as $d) {
            $baseCode = \App\Models\TdsSection::baseCode((string) $d->section_code);
            $returnCode = $this->spec->returnCodeFor($baseCode, $d->deductee_type);
            if ($returnCode === null) {
                $this->errors[] = sprintf(
                    'Section “%s” (deductee %s) has no Form 26Q return-code mapping. Add it to Form26qSpec or use a standard section.',
                    $d->section_code, $d->deductee_name,
                );
            }

            $hasPan = $this->spec->isValidPan($d->deductee_pan);
            if (! $hasPan && trim((string) $d->deductee_pan) !== '') {
                // A present-but-malformed PAN is a data error worth surfacing.
                $this->errors[] = sprintf(
                    'Deductee “%s” has an invalid PAN “%s”. Fix it on the ledger, or clear it (Section 206AA no-PAN).',
                    $d->deductee_name, $d->deductee_pan,
                );
            }

            $rows[] = [
                'id' => (int) $d->id,
                'name' => $d->deductee_name,
                'pan' => $hasPan ? $d->deductee_pan : Form26qSpec::PAN_NOT_AVAILABLE,
                'has_pan' => $hasPan,
                'section_code' => $returnCode,
                'rate' => (float) $d->rate,
                'payment_paise' => self::paise($d->payment_amount),
                'tds_paise' => self::paise($d->deducted_amount),
                'date' => Carbon::parse($d->v_date),
            ];
        }

        // Challans of the quarter, oldest first, with validated identifiers.
        // Challans that discharge this quarter's tax. The window runs from the quarter
        // start to one month past its end, because TDS for a quarter is routinely
        // DEPOSITED in the following month (e.g. June's tax by 7 July) — a challan dated
        // then still belongs to this quarter's return. (Precise year-wide FIFO attribution
        // across quarter boundaries is a documented simplification.)
        $challanTo = $to->copy()->addMonth();
        $challans = TdsChallan::query()
            ->join('vouchers', 'vouchers.id', '=', 'tds_challans.voucher_id')
            ->whereNull('vouchers.scenario_id')
            ->whereBetween('tds_challans.deposit_date', [$from->toDateString(), $challanTo->toDateString()])
            ->orderBy('tds_challans.deposit_date')->orderBy('tds_challans.id')
            ->get(['tds_challans.*', 'vouchers.date as v_date']);

        foreach ($challans as $ch) {
            if (! $this->spec->isValidBsr($ch->bsr_code)) {
                $this->errors[] = "Challan on {$ch->deposit_date?->toDateString()} has an invalid BSR code “{$ch->bsr_code}” (must be 7 digits).";
            }
            if (! $this->spec->isValidChallanNumber($ch->challan_number)) {
                $this->errors[] = "Challan on {$ch->deposit_date?->toDateString()} has an invalid challan number “{$ch->challan_number}” (must be 1-5 digits).";
            }
        }

        // Map whole deductions to challans, FIFO. Refuses on partial/uncovered.
        $mappedChallans = $this->mapDeductionsToChallans($rows, $challans);

        return [
            'fy_start' => $fyStart,
            'quarter' => $quarter,
            'from' => $from,
            'to' => $to,
            'profile' => $profile,
            'challans' => $mappedChallans,
            'batch_total_paise' => array_sum(array_map(fn ($c) => $c['total_paise'], $mappedChallans)),
        ];
    }

    /**
     * FIFO-assign whole deductions to challans. A deductee row belongs to exactly one
     * challan (the 26Q model), and a challan's deductee TDS must sum to the challan amount.
     * Any deduction that would straddle two challans, any challan not exactly covered, and
     * any unremitted tax is refused with a row-named message.
     */
    private function mapDeductionsToChallans(array $rows, $challans): array
    {
        $out = [];
        $ci = 0;
        $chArr = $challans->all();
        $remaining = isset($chArr[0]) ? self::paise($chArr[0]->total_amount) : 0;
        $assigned = [];

        foreach ($rows as $row) {
            $tds = $row['tds_paise'];
            if ($ci >= count($chArr)) {
                $this->errors[] = sprintf(
                    'Deduction for “%s” (%s TDS) has no challan to attach to — record the remittance (Dr TDS Payable / Cr Bank) with its BSR and challan number before filing.',
                    $row['name'], self::money($tds),
                );
                continue;
            }
            if ($tds > $remaining + 0) {
                // The deduction does not fit the current challan's remaining capacity.
                $ch = $chArr[$ci];
                $this->errors[] = sprintf(
                    'Challan #%d (%s) is not exactly covered by whole deductions — deduction for “%s” (%s) overflows its remaining %s. '
                    .'A deductee row cannot span two challans; deposit amounts must match the tax deducted.',
                    $ci + 1, self::money(self::paise($ch->total_amount)), $row['name'], self::money($tds), self::money($remaining),
                );
                continue;
            }
            $assigned[$ci][] = $row;
            $remaining -= $tds;
            // Challan fully consumed → advance to the next.
            if ($remaining === 0) {
                $ci++;
                $remaining = isset($chArr[$ci]) ? self::paise($chArr[$ci]->total_amount) : 0;
            }
        }

        // Any challan with leftover capacity was over-deposited relative to the quarter's
        // deductions — refuse (excess/advance-deposit handling is out of scope).
        if ($ci < count($chArr) && $remaining !== 0 && ! empty($assigned)) {
            $ch = $chArr[$ci];
            $this->errors[] = sprintf(
                'Challan #%d (%s) has %s of unallocated deposit — it exceeds the quarter’s remaining deductions. '
                .'Excess/advance deposits are out of scope for this export.',
                $ci + 1, self::money(self::paise($ch->total_amount)), self::money($remaining),
            );
        }

        foreach ($chArr as $i => $ch) {
            $rowsForCh = $assigned[$i] ?? [];
            if (empty($rowsForCh)) {
                continue; // an empty challan is only reached in an error path already reported
            }
            $tdsSum = array_sum(array_map(fn ($r) => $r['tds_paise'], $rowsForCh));
            $out[] = [
                'record_no' => count($out) + 1,
                'bsr_code' => $ch->bsr_code,
                'challan_number' => $ch->challan_number,
                'deposit_date' => $ch->deposit_date,
                'minor_head' => $ch->minor_head ?: '200',
                'total_paise' => $tdsSum,        // the challan's tax = Σ its deductions' TDS
                'deductions' => $rowsForCh,
            ];
        }

        return $out;
    }

    private function validateDeductorProfile(array $p): void
    {
        $need = [
            'tan' => 'TAN', 'pan' => 'PAN', 'name' => 'name', 'address1' => 'address line 1',
            'state_code' => 'state code', 'pincode' => 'PIN code', 'email' => 'email', 'phone' => 'phone',
            'type' => 'deductor category', 'resp_name' => 'responsible person name',
            'resp_designation' => 'responsible person designation', 'resp_pan' => 'responsible person PAN',
            'resp_address1' => 'responsible person address', 'resp_state_code' => 'responsible person state',
            'resp_pincode' => 'responsible person PIN', 'resp_email' => 'responsible person email',
            'resp_phone' => 'responsible person phone',
        ];
        foreach ($need as $key => $label) {
            if (trim((string) ($p[$key] ?? '')) === '') {
                $this->errors[] = "Deductor {$label} is not set (Company Features → TDS). The 26Q return cannot be filed without it.";
            }
        }
        if (! empty($p['tan']) && ! $this->spec->isValidTan($p['tan'])) {
            $this->errors[] = "Deductor TAN “{$p['tan']}” is not a valid TAN (4 letters, 5 digits, 1 letter).";
        }
        if (! empty($p['pan']) && ! $this->spec->isValidPan($p['pan'])) {
            $this->errors[] = "Deductor PAN “{$p['pan']}” is not a valid PAN.";
        }
        if (! empty($p['resp_pan']) && ! $this->spec->isValidPan($p['resp_pan'])) {
            $this->errors[] = "Responsible person PAN “{$p['resp_pan']}” is not a valid PAN.";
        }
        if (! empty($p['type']) && ! $this->spec->isValidDeductorType($p['type'])) {
            $this->errors[] = "Deductor category “{$p['type']}” is not a valid code (Annexure 4).";
        }
        foreach (['state_code' => 'Deductor', 'resp_state_code' => 'Responsible person'] as $key => $who) {
            if (! empty($p[$key]) && ! $this->spec->isValidStateCode($p[$key])) {
                $this->errors[] = "{$who} state code “{$p[$key]}” is not a valid state code (Annexure 1).";
            }
        }
    }

    // ---- record builders (each field traces to a spec Sr. No.) ---------------

    /** File Header — 18 fields. */
    private function buildFileHeader(int $lineNo, array $data): string
    {
        $f = array_fill(1, 18, '');
        $f[1] = $lineNo;                                   // Line Number
        $f[2] = 'FH';                                      // Record Type
        $f[3] = Form26qSpec::FILE_TYPE;                    // File Type = NS1
        $f[4] = Form26qSpec::UPLOAD_TYPE;                  // Upload Type = R
        $f[5] = self::today();                             // File Creation Date (ddmmyyyy)
        $f[6] = 1;                                         // File Sequence No.
        $f[7] = 'D';                                       // Uploader Type = D
        $f[8] = $data['profile']['tan'];                   // TAN of Deductor
        $f[9] = 1;                                         // Total No. of Batches
        $f[10] = Form26qSpec::RPU_NAME;                    // Name of Return Preparation Utility
        // 11-18 — hash/version fields, "no value should be specified".
        return $this->join($f);
    }

    /** Batch Header — 72 fields. */
    private function buildBatchHeader(int $lineNo, array $data): string
    {
        $p = $data['profile'];
        $f = array_fill(1, 72, '');
        $f[1] = $lineNo;                                   // Line Number
        $f[2] = 'BH';                                      // Record Type
        $f[3] = 1;                                         // Batch Number
        $f[4] = count($data['challans']);                  // Count of Challan Records
        $f[5] = Form26qSpec::FORM_NUMBER;                  // Form Number = 140
        // 6-12 — not applicable / correction-only.
        $f[13] = $p['tan'];                                // TAN of Deductor
        $f[15] = $p['pan'];                                // PAN of Deductor
        $f[16] = self::assessmentYear($data['fy_start']);  // Assessment Yr (AY, YYYYYY)
        $f[17] = self::taxYear($data['fy_start']);          // Tax Year (YYYYYY)
        $f[18] = 'Q'.$data['quarter'];                     // Period
        $f[19] = $p['name'];                               // Name of Deductor
        $f[20] = 'INDIA';                                  // Deductor Address 6 (Country/Region)
        $f[21] = $p['address1'];                           // Deductor Address1
        $f[22] = $p['address2'] ?? '';                     // Deductor Address2 (optional)
        $f[26] = $p['state_code'];                         // Deductor's State
        $f[27] = $p['pincode'];                            // Deductor's Pincode
        $f[28] = $p['email'];                              // Deductor's Email
        $f[29] = '91';                                     // Deductor Contact Country Code
        $f[30] = $p['phone'];                              // Deductor Contact Number
        $f[32] = $p['type'];                               // Deductor Type (Annexure 4)
        $f[33] = $p['resp_name'];                          // Name of Person responsible
        $f[34] = $p['resp_designation'];                   // Designation
        $f[35] = $p['resp_address1'];                      // Responsible Person Address1
        $f[40] = $p['resp_state_code'];                    // Responsible Person State
        $f[41] = $p['resp_pincode'];                       // Responsible Person PIN
        $f[42] = $p['resp_email'];                         // Responsible Person Email
        $f[43] = 'INDIA';                                  // Responsible Persons country region
        $f[44] = '91';                                     // Responsible Person Contact Country Code
        $f[45] = $p['resp_phone'];                         // Responsible Person Contact Number
        $f[47] = Form26qSpec::amount($data['batch_total_paise'] / 100); // Batch Total of Deposit Amount
        $f[52] = 'N';                                      // Whether regular statement filed earlier
        $f[59] = $p['resp_pan'];                           // PAN of Responsible Person
        // 60-72 — fillers / not-applicable / govt-only (AIN, GSTN, 194P). Empty.
        return $this->join($f);
    }

    /** Challan Detail — 30 fields. */
    private function buildChallan(int $lineNo, array $ch): string
    {
        $amount = Form26qSpec::amount($ch['total_paise'] / 100);
        $f = array_fill(1, 30, '');
        $f[1] = $lineNo;                                   // Line Number
        $f[2] = 'CD';                                      // Record Type
        $f[3] = 1;                                         // Batch Number
        $f[4] = $ch['record_no'];                          // Challan-Detail Record Number
        $f[5] = count($ch['deductions']);                  // Count of Deductee Records
        $f[6] = 'N';                                       // NIL Challan Indicator
        $f[8] = $amount;                                   // Total tax Deducted (income-tax portion)
        $f[9] = '0.00';                                    // Total Interest
        $f[10] = '0.00';                                   // Total fee
        $f[11] = '0.00';                                   // Total Penalty/Others
        $f[12] = $amount;                                  // Total of Deposit Amount (B+C+D+E)
        $f[13] = 'C';                                      // Mode of payment = C (bank challan)
        $f[15] = $ch['bsr_code'];                          // BSR Code
        $f[17] = $ch['challan_number'];                    // Bank Challan No/DDO Serial Number
        $f[19] = Form26qSpec::date($ch['deposit_date']);   // Date of Bank Challan (ddmmyyyy)
        $f[21] = $amount;                                  // Total Tax Deposited (Σ DD col L)
        $f[22] = $amount;                                  // Total Tax Deducted (Σ DD col J)
        $f[23] = $ch['minor_head'];                        // Minor Head of Challan (200)
        return $this->join($f);
    }

    /** Deductee Detail — 45 fields. */
    private function buildDeductee(int $lineNo, int $ddNo, array $ch, array $dd): string
    {
        $tds = Form26qSpec::amount($dd['tds_paise'] / 100);
        $f = array_fill(1, 45, '');
        $f[1] = $lineNo;                                   // Line Number
        $f[2] = 'DD';                                      // Record Type
        $f[3] = 1;                                         // Batch Number
        $f[4] = $ddNo;                                     // Deductee Detail Record No (running 1..N)
        $f[5] = $ch['record_no'];                          // Challan reference Number
        $f[6] = 'O';                                       // Mode = O
        $f[8] = $dd['pan'];                                // Deductee's PAN (or PANNOTAVBL)
        $f[9] = $dd['name'];                               // Name of the Deductee
        $f[15] = $dd['section_code'];                      // Section Code (Annexure 2)
        $f[18] = 'Y';                                      // Whether tax deducted has been deposited
        $f[19] = Form26qSpec::date($dd['date']);           // Date of payment / Credited
        $f[20] = Form26qSpec::amount($dd['payment_paise'] / 100); // Amount Paid / Credited (base)
        $f[24] = $tds;                                     // Total Tax Deducted
        $f[25] = $tds;                                     // Total Tax Deposited
        $f[27] = Form26qSpec::date($dd['date']);           // Date of deduction
        $f[28] = Form26qSpec::rate($dd['rate']);           // Rate at which Tax Deducted (4 dp)
        // Field 32 — non-deduction / higher-rate flag (Annexure 6). Only the no-PAN
        // higher-rate case (flag C) is emitted; all other rows are regular deductions
        // with no flag (certificate / lower-rate handling is out of scope — see README).
        if (! $dd['has_pan']) {
            $f[32] = Form26qSpec::FLAG_HIGHER_RATE_NO_PAN; // C — higher rate, non-furnishing of PAN
        }
        return $this->join($f);
    }

    // ---- the centralized pad + join ------------------------------------------

    /**
     * Format one field for the caret-delimited file. The spec (general note 9) is a
     * VARIABLE-width delimited format — leading zeros and trailing spaces are NOT to be
     * added — so "padding" here means format-and-cap, not space-pad: cast to string, and
     * (defensively) cap alphanumeric fields at nothing beyond what the builders already
     * shaped. The '^' delimiter itself can never appear inside a value.
     */
    public function pad($value, string $type = 'AN'): string
    {
        $s = (string) $value;
        // The delimiter must never leak into a field value.
        return str_replace(Form26qSpec::DELIMITER, ' ', $s);
    }

    /** Join a 1-indexed field array into one caret-delimited record. */
    private function join(array $fields): string
    {
        ksort($fields);
        return implode(Form26qSpec::DELIMITER, array_map(fn ($v) => $this->pad($v), $fields));
    }

    // ---- quarter + fiscal-year helpers ---------------------------------------

    /** [from, to] Carbon dates for a quarter. Q1=Apr-Jun, Q2=Jul-Sep, Q3=Oct-Dec, Q4=Jan-Mar. */
    public static function quarterRange(int $fyStart, int $quarter): array
    {
        return match ($quarter) {
            1 => [Carbon::create($fyStart, 4, 1), Carbon::create($fyStart, 6, 30)],
            2 => [Carbon::create($fyStart, 7, 1), Carbon::create($fyStart, 9, 30)],
            3 => [Carbon::create($fyStart, 10, 1), Carbon::create($fyStart, 12, 31)],
            4 => [Carbon::create($fyStart + 1, 1, 1), Carbon::create($fyStart + 1, 3, 31)],
            default => throw new \InvalidArgumentException("Quarter must be 1-4, got {$quarter}."),
        };
    }

    /** Tax Year (YYYYYY) — e.g. 202627 for FY 2026-27. */
    public static function taxYear(int $fyStart): string
    {
        return sprintf('%04d%02d', $fyStart, ($fyStart + 1) % 100);
    }

    /** Assessment Year (YYYYYY) — AY is the year AFTER the tax year, e.g. 202728 for FY 2026-27. */
    public static function assessmentYear(int $fyStart): string
    {
        return sprintf('%04d%02d', $fyStart + 1, ($fyStart + 2) % 100);
    }

    private static function today(): string
    {
        return Carbon::today()->format('dmY');
    }

    private static function paise($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function money(int $paise): string
    {
        return number_format($paise / 100, 2);
    }
}
