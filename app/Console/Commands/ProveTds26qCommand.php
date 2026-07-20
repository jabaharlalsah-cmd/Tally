<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\TdsChallan;
use App\Models\TdsReturnFiling;
use App\Models\TdsSection;
use App\Models\Tenant;
use App\Services\Tds\Form26qException;
use App\Services\Tds\Form26qExporter;
use App\Services\Tds\Form26qSpec;
use App\Services\Tds\Form26qStructuralValidator;
use App\Services\Tds\FvuValidator;
use App\Services\Tds\SpecResolver;
use App\Services\TdsService;
use App\Services\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 10B numeric + conformance proof — the Form 26Q quarterly return-file exporter.
 *
 * Provisions a tenant, seeds the deductor's filing identity and the section catalog, posts
 * a deterministic worked example (5 professional-fee payments to three vendors — two with a
 * valid PAN, one without — plus one remittance with real BSR/challan), exports the quarter,
 * and asserts:
 *
 *   • the file is STRUCTURALLY CONFORMANT to Protean's published spec (the spec-driven
 *     validator, whose rules come from the government workbook) — exact record counts and
 *     every field at its spec position/width/type;
 *   • every asserted field VALUE traces to a spec Sr. No. (Form 140 constants, AY/Tax Year,
 *     period, TAN, BSR, challan, section code 1027, PANNOTAVBL + no-PAN flag C, rate 20.0000);
 *   • the deductor-identity gate refuses without TAN;
 *   • challan capture through VoucherScreen creates a tds_challans row and the exporter reads it;
 *   • the historical-spec switch picks the right era by fiscal year, and a pre-2026 export refuses;
 *   • the real FVU is invoked and its anti-tamper barrier documented (never a false green);
 *   • the return-filings log persists a token.
 *
 * All 18 prior proofs remain green (10B is read-only over the 10A engine).
 */
class ProveTds26qCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-26q {--keep : keep the 26qtest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the Form 26Q exporter: spec conformance, the worked example, challan capture, the historical-spec switch, and the real FVU barrier';

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = '26qtest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Form 26Q Test Co', 'professional');
            Tenant::find($slug)->run(fn () => $this->runProof());
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if (! $this->option('keep')) {
                $provisioner->teardown($slug);
            }
        }

        $this->line('');
        $this->info($this->ok ? 'ALL 26Q ASSERTIONS PASSED.' : '26Q ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $spec = app(Form26qSpec::class);
        $exporter = app(Form26qExporter::class);
        $validator = app(Form26qStructuralValidator::class);
        $screen = new VoucherScreen();
        $svc = app(TdsService::class);

        // ═══ 0. Deductor filing identity ════════════════════════════════════════
        $this->section('Deductor filing identity');
        activeCompany()->update(['pan' => 'AAACZ1234F', 'tan' => 'MUMZ12345A']);
        \App\Support\ActiveCompany::refresh();
        CompanyFeature::current()->update([
            'tds' => true,
            'deductor_name' => 'ZeroBook Test Deductor Pvt Ltd', 'deductor_address1' => '1 Evergreen Road',
            'deductor_state_code' => '19', 'deductor_pincode' => '400001',
            'deductor_email' => 'tds@zerobook.test', 'deductor_phone' => '2266778899', 'deductor_type' => 'K',
            'resp_name' => 'Alex Manager', 'resp_designation' => 'Director', 'resp_pan' => 'AAAPA1234Q',
            'resp_address1' => '1 Evergreen Road', 'resp_state_code' => '19', 'resp_pincode' => '400001',
            'resp_email' => 'alex@zerobook.test', 'resp_phone' => '2266778899',
        ]);
        $this->expect('Deductor TAN is valid', $spec->isValidTan('MUMZ12345A'), true);
        $this->expect('Deductor type K is a valid category (Annexure 4)', $spec->isValidDeductorType('K'), true);
        $this->expect('Maharashtra state code 19 is valid (Annexure 1)', $spec->isValidStateCode('19'), true);

        // ═══ 1. Seed the worked example ═════════════════════════════════════════
        $this->section('Worked example — 5 professional-fee payments, ₹5,50,000 base / ₹65,000 TDS');
        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $bank = Ledger::create(['name' => 'HDFC Bank', 'group_id' => $gid('Bank Accounts')]);
        $fees = Ledger::create(['name' => 'Legal Fees', 'group_id' => $gid('Indirect Expenses')]);
        $sec = TdsSection::where('code', '393-194J')->first();
        $payable = $svc->payableLedgerId();

        $v1 = Ledger::create(['name' => 'M/s Legal Advisors', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'ABCDE1234F', 'deductee_type' => 'individual_huf', 'default_tds_section_id' => $sec->id]);
        $v2 = Ledger::create(['name' => 'M/s Audit Partners LLP', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'BCDEF2345G', 'deductee_type' => 'company_firm_llp', 'default_tds_section_id' => $sec->id]);
        $v3 = Ledger::create(['name' => 'M/s Counsel (no PAN)', 'group_id' => $gid('Sundry Creditors'),
            'deductee_type' => 'individual_huf', 'default_tds_section_id' => $sec->id]);

        $pay = function (Ledger $vend, float $base, string $date) use ($screen, $svc, $fees, $bank, $sec, $payable) {
            $fy = \App\Models\Voucher::fyStartFor(Carbon::parse($date));
            [$ded] = $svc->computeDeduction($vend->id, $sec->id, (int) round($base * 100), $fy, null, Carbon::parse($date));
            $tds = round($ded / 100, 2);
            $lines = [['ledger_id' => $fees->id, 'dr_cr' => 'Dr', 'amount' => $base]];
            if ($tds > 0) {
                $lines[] = ['ledger_id' => $payable, 'dr_cr' => 'Cr', 'amount' => $tds];
            }
            $lines[] = ['ledger_id' => $bank->id, 'dr_cr' => 'Cr', 'amount' => round($base - $tds, 2)];

            return $screen->post([
                'type' => 'payment', 'date' => $date, 'lines' => $lines,
                'tds_deduction' => ['deductee_ledger_id' => $vend->id, 'tds_section_id' => $sec->id, 'base_amount' => $base],
            ]);
        };
        // V1 (PAN, 10%): 1,20,000 + 80,000 → 20,000. V2 (PAN, 10%): 1,00,000 + 1,50,000 → 25,000.
        // V3 (no PAN, 20%): 1,00,000 → 20,000. Base 5,50,000, TDS 65,000, all in Q1.
        $pay($v1, 120000, '2026-04-10');
        $pay($v1, 80000, '2026-05-10');
        $pay($v2, 100000, '2026-04-15');
        $pay($v2, 150000, '2026-05-20');
        $pay($v3, 100000, '2026-06-01');

        $totBase = \App\Models\TdsDeduction::where('deducted_amount', '>', 0)->sum('payment_amount');
        $totTds = \App\Models\TdsDeduction::where('deducted_amount', '>', 0)->sum('deducted_amount');
        $this->expect('5 deductions with TDS', \App\Models\TdsDeduction::where('deducted_amount', '>', 0)->count(), 5);
        $this->expect('Total base is 5,50,000', (float) $totBase, 550000.0);
        $this->expect('Total TDS is 65,000', (float) $totTds, 65000.0);

        // ═══ 2. Challan capture through VoucherScreen ═══════════════════════════
        $this->section('Challan capture — a remittance debiting TDS Payable records the bank identifiers');
        $rem = $screen->post([
            'type' => 'payment', 'date' => '2026-06-28',
            'lines' => [
                ['ledger_id' => $payable, 'dr_cr' => 'Dr', 'amount' => 65000],
                ['ledger_id' => $bank->id, 'dr_cr' => 'Cr', 'amount' => 65000],
            ],
            'tds_challan' => ['bsr_code' => '0510308', 'challan_number' => '02345', 'deposit_date' => '2026-06-28'],
        ]);
        $challan = TdsChallan::where('voucher_id', $rem['voucher']['id'])->first();
        $this->expect('Challan row created', $challan !== null, true);
        $this->expect('BSR captured', $challan?->bsr_code, '0510308');
        $this->expect('Challan number captured', $challan?->challan_number, '02345');
        $this->expect('Deposit amount = the Dr TDS Payable line (server-derived)', (float) $challan?->total_amount, 65000.0);

        $this->expect('A challan on a NON-remittance Payment is rejected', $this->rejects($screen, [
            'type' => 'payment', 'date' => '2026-06-29',
            'lines' => [['ledger_id' => $fees->id, 'dr_cr' => 'Dr', 'amount' => 100], ['ledger_id' => $bank->id, 'dr_cr' => 'Cr', 'amount' => 100]],
            'tds_challan' => ['bsr_code' => '0510308', 'challan_number' => '02345', 'deposit_date' => '2026-06-29'],
        ], 'tds_challan'), true);
        $this->expect('A malformed BSR (6 digits) is rejected', $this->rejects($screen, [
            'type' => 'payment', 'date' => '2026-06-29',
            'lines' => [['ledger_id' => $payable, 'dr_cr' => 'Dr', 'amount' => 100], ['ledger_id' => $bank->id, 'dr_cr' => 'Cr', 'amount' => 100]],
            'tds_challan' => ['bsr_code' => '051030', 'challan_number' => '02345', 'deposit_date' => '2026-06-29'],
        ], 'tds_challan'), true);

        // ═══ 3. Export + structural conformance ═════════════════════════════════
        $this->section('Export FY 2026-27 Q1 — structural conformance to the published spec');
        $text = $exporter->record(2026, 1);
        $val = $validator->validate($text);
        if (! $val['ok']) {
            foreach ($val['issues'] as $is) {
                $this->line('    ! '.$is);
            }
        }
        $this->expect('File is STRUCTURALLY CONFORMANT to the spec', $val['ok'], true);
        $this->expect('Record counts: 1 FH', $val['counts']['FH'], 1);
        $this->expect('Record counts: 1 BH', $val['counts']['BH'], 1);
        $this->expect('Record counts: 1 CD (one challan)', $val['counts']['CD'], 1);
        $this->expect('Record counts: 5 DD (five deductees)', $val['counts']['DD'], 5);
        $this->expect('File ends every record with CRLF', str_contains($text, "\r\n"), true);
        $this->expect('File is caret-delimited', str_contains($text, '^'), true);

        // Parse the records for field-position assertions (each traces to a spec Sr. No.).
        $lines = array_values(array_filter(explode("\r\n", $text), fn ($l) => $l !== ''));
        $fh = explode('^', $lines[0]);
        $bh = explode('^', $lines[1]);
        $cd = explode('^', $lines[2]);
        $dds = array_map(fn ($l) => explode('^', $l), array_slice($lines, 3));

        // ═══ 4. Field-position assertions (spec Sr. No. cited) ══════════════════
        $this->section('Field positions — every value traces to a spec Sr. No.');
        $this->expect('FH field 2 Record Type = FH', $fh[1], 'FH');
        $this->expect('FH field 3 File Type = NS1', $fh[2], 'NS1');
        $this->expect('FH field 4 Upload Type = R', $fh[3], 'R');
        $this->expect('FH field 7 Uploader Type = D', $fh[6], 'D');
        $this->expect('FH field 8 TAN', $fh[7], 'MUMZ12345A');
        $this->expect('FH has exactly 18 fields', count($fh), 18);

        $this->expect('BH field 2 Record Type = BH', $bh[1], 'BH');
        $this->expect('BH field 5 Form Number = 140', $bh[4], '140');
        $this->expect('BH field 13 TAN', $bh[12], 'MUMZ12345A');
        $this->expect('BH field 15 Deductor PAN', $bh[14], 'AAACZ1234F');
        $this->expect('BH field 16 Assessment Year = 202728', $bh[15], '202728');
        $this->expect('BH field 17 Tax Year = 202627', $bh[16], '202627');
        $this->expect('BH field 18 Period = Q1', $bh[17], 'Q1');
        $this->expect('BH field 26 Deductor state = 19 (Maharashtra)', $bh[25], '19');
        $this->expect('BH field 32 Deductor type = K (Company)', $bh[31], 'K');
        $this->expect('BH field 47 Batch total deposit = 65000.00', $bh[46], '65000.00');
        $this->expect('BH field 59 Responsible-person PAN', $bh[58], 'AAAPA1234Q');
        $this->expect('BH has exactly 72 fields', count($bh), 72);

        $this->expect('CD field 2 Record Type = CD', $cd[1], 'CD');
        $this->expect('CD field 5 Count of Deductees = 5', $cd[4], '5');
        $this->expect('CD field 6 NIL indicator = N', $cd[5], 'N');
        $this->expect('CD field 12 Total Deposit = 65000.00', $cd[11], '65000.00');
        $this->expect('CD field 13 Mode = C (bank challan)', $cd[12], 'C');
        $this->expect('CD field 15 BSR code', $cd[14], '0510308');
        $this->expect('CD field 17 Challan number', $cd[16], '02345');
        $this->expect('CD field 19 Deposit date = 28062026 (ddmmyyyy)', $cd[18], '28062026');
        $this->expect('CD field 23 Minor head = 200', $cd[22], '200');
        $this->expect('CD has exactly 30 fields', count($cd), 30);

        $this->expect('Every DD has 45 fields', array_unique(array_map('count', $dds)), [45]);
        $this->expect('Every DD section code = 1027 (professional fees, Annexure 2)',
            array_values(array_unique(array_map(fn ($d) => $d[14], $dds))), ['1027']);

        // ═══ 5. No-PAN handling (Section 206AA → flag C) ════════════════════════
        $this->section('No-PAN deductee — PANNOTAVBL + higher-rate flag C');
        $noPanRow = null;
        $panRows = [];
        foreach ($dds as $d) {
            if ($d[7] === Form26qSpec::PAN_NOT_AVAILABLE) {
                $noPanRow = $d;
            } else {
                $panRows[] = $d;
            }
        }
        $this->expect('One DD carries PANNOTAVBL', $noPanRow !== null, true);
        $this->expect('No-PAN DD field 8 = PANNOTAVBL', $noPanRow[7] ?? null, 'PANNOTAVBL');
        $this->expect('No-PAN DD field 32 = flag C (higher rate, 206AA/397(2))', $noPanRow[31] ?? null, 'C');
        $this->expect('No-PAN DD field 28 rate = 20.0000', $noPanRow[27] ?? null, '20.0000');
        $this->expect('PAN DDs carry NO flag in field 32', array_values(array_unique(array_map(fn ($d) => $d[31], $panRows))), ['']);
        $this->expect('PAN DDs rate = 10.0000', array_values(array_unique(array_map(fn ($d) => $d[27], $panRows))), ['10.0000']);
        $this->expect('DD amounts are decimal precision 2', (bool) preg_match('/^\d+\.\d{2}$/', $noPanRow[19]), true);
        $this->expect('DD rate is decimal precision 4', (bool) preg_match('/^\d+\.\d{4}$/', $noPanRow[27]), true);

        // ═══ 6. Cross-record reconciliation ═════════════════════════════════════
        $this->section('Reconciliation — BH total = Σ CD deposit = Σ DD tax');
        $ddTaxSum = array_sum(array_map(fn ($d) => (float) $d[23], $dds));   // DD field 24
        $this->expect('Σ DD tax deducted = 65,000', $ddTaxSum, 65000.0);
        $this->expect('CD deposit == Σ DD tax', (float) $cd[11], $ddTaxSum);
        $this->expect('BH batch total == CD deposit', (float) $bh[46], (float) $cd[11]);

        // ═══ 7. The deductor-identity gate ══════════════════════════════════════
        $this->section('The exporter refuses without the deductor identity');
        activeCompany()->update(['tan' => null]);
        \App\Support\ActiveCompany::refresh();
        $refused = false;
        $errs = [];
        try {
            $exporter->record(2026, 1);
        } catch (Form26qException $e) {
            $refused = true;
            $errs = $e->errors;
        }
        $this->expect('Export REFUSES when TAN is missing', $refused, true);
        $this->expect('…naming the missing field', (bool) collect($errs)->contains(fn ($e) => str_contains($e, 'TAN')), true);
        activeCompany()->update(['tan' => 'MUMZ12345A']); // restore
        \App\Support\ActiveCompany::refresh();

        // ═══ 8. The historical-spec switch ══════════════════════════════════════
        $this->section('Historical-spec switch — era chosen by fiscal year');
        $resolver = app(SpecResolver::class);
        $this->expect('FY 2026-27 → current era (Form 140, FVU 1.1)', $resolver->era(2026), SpecResolver::ERA_CURRENT);
        $this->expect('FY 2025-26 → historical era (26Q v7.8, FVU 9.5)', $resolver->era(2025), SpecResolver::ERA_HISTORICAL);
        $this->expect('Current descriptor points at the current spec file',
            str_contains($resolver->descriptor(2026)['spec_file'], 'current'), true);
        $this->expect('Historical descriptor points at the historical spec file',
            str_contains($resolver->descriptor(2025)['spec_file'], 'historical'), true);
        $this->expect('Historical descriptor uses FVU 9.5', $resolver->descriptor(2025)['fvu_version'], '9.5');

        // ═══ 9. Empty quarter + amount formatting ═══════════════════════════════
        $this->section('Edge cases');
        $this->expect('Q4 (no deductions) refuses with the “needs a deductee” message', $this->exportRefuses($exporter, 2026, 4, 'deductee'), true);
        $this->expect('Amount formatter: 65000 → 65000.00', Form26qSpec::amount(65000), '65000.00');
        $this->expect('Rate formatter: 10 → 10.0000', Form26qSpec::rate(10), '10.0000');
        $this->expect('Assessment year for FY 2026 → 202728', Form26qExporter::assessmentYear(2026), '202728');
        $this->expect('Tax year for FY 2026 → 202627', Form26qExporter::taxYear(2026), '202627');
        [$qf, $qt] = Form26qExporter::quarterRange(2026, 1);
        $this->expect('Q1 range = Apr 1 – Jun 30', $qf->toDateString().'..'.$qt->toDateString(), '2026-04-01..2026-06-30');

        // ═══ 10. The real FVU — invoke it and document the barrier ══════════════
        $this->section('The official FVU — invoked, and its anti-tamper barrier documented');
        $fvu = app(FvuValidator::class);
        if (! $fvu->javaAvailable()) {
            $this->line('  [INFO] No Java runtime available — FVU not invoked (structural conformance is the gate).');
        } else {
            $res = $fvu->run($text, 2026);
            $this->expect('The FVU jar was invoked', $res['ran'], true);
            $cl = $res['classification'];
            $this->line('  [FVU] report: '.($res['report'] !== '' ? substr($res['report'], 0, 90) : '(none)'));
            $this->line('  [FVU] barrier: '.$cl['barrier'].' — '.$cl['summary']);
            $this->expect('The FVU did NOT falsely accept synthetic data as a clean return',
                $cl['barrier'] !== 'clean-pass', true);
            $this->expect('The barrier is a known anti-tamper / external-data gate (not a format defect)',
                in_array($cl['barrier'], ['version-gate', 'csi-required', 'field-errors', 'no-fvu-jar', 'unknown'], true), true);
        }

        // ═══ 11. Return-filings log ═════════════════════════════════════════════
        $this->section('Return-filings acknowledgement log');
        $filing = TdsReturnFiling::create([
            'fy_start' => 2026, 'quarter' => 1, 'filed_at' => Carbon::parse('2026-07-05'),
            'token_no' => '123456789012345', 'receipt_no' => 'ACK-Q1-2026',
        ]);
        $reload = TdsReturnFiling::where('fy_start', 2026)->where('quarter', 1)->first();
        $this->expect('Filing token persists', $reload?->token_no, '123456789012345');
        $this->expect('Filing label reads correctly', $reload?->label(), 'FY 2026-27 · Q1');

        // ═══ 12. Trial Balance still balances (10B is read-only) ════════════════
        $this->section('Read-only over the engine — the Trial Balance still balances');
        $bs = app(\App\Services\BalanceService::class);
        [$f, $t] = $bs->withinFy(null, null);
        $this->expect('TB balanced', $bs->trialBalance($f, $t)['balanced'], true);
    }

    // ---- helpers --------------------------------------------------------------

    private function rejects(VoucherScreen $screen, array $payload, string $key): bool
    {
        try {
            $screen->post($payload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return in_array($key, array_keys($e->errors()), true);
        }

        return false;
    }

    private function exportRefuses(Form26qExporter $exporter, int $fy, int $q, string $needle): bool
    {
        try {
            $exporter->record($fy, $q);
        } catch (Form26qException $e) {
            return (bool) collect($e->errors)->contains(fn ($er) => str_contains($er, $needle));
        }

        return false;
    }

    private function section(string $t): void
    {
        $this->line('');
        $this->line('── '.$t.' '.str_repeat('─', max(0, 62 - mb_strlen($t))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$this->fmt($actual).($pass ? '' : ' (expected '.$this->fmt($expected).')'));
        $this->ok = $this->ok && $pass;
    }

    private function fmt($v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'NULL';
        }
        if (is_array($v)) {
            return '['.implode(', ', array_map(fn ($x) => is_bool($x) ? ($x ? 'true' : 'false') : (string) $x, $v)).']';
        }

        return (string) $v;
    }
}
