<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Ledger;
use App\Models\TdsDeducteeYtd;
use App\Models\TdsDeduction;
use App\Models\TdsSection;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Services\BalanceService;
use App\Services\TdsService;
use App\Services\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 10A numeric proof — the TDS deduction engine, posted through the SAME
 * VoucherScreen::post() every other voucher uses. It proves, to the paise:
 *
 *   • The rate table is DATA: the Section 393 sub-provisions are in force for FY 2026-27
 *     and every repealed 194-series row is filtered out of the live list.
 *   • Below the threshold nothing is deducted, and the voucher stays two lines.
 *   • THE CATCH-UP: the payment that crosses the threshold is taxed on the WHOLE
 *     year-to-date aggregate, not on itself — ₹5,500 withheld from a ₹15,000 bill.
 *   • After crossing, ordinary payments deduct on their own base with no catch-up.
 *   • Section 206AA: no PAN ⇒ 20% (or the section rate, if higher).
 *   • The taxable base EXCLUDES GST, and the server derives it — the client cannot widen it.
 *   • A tampered TDS amount is REJECTED by the server and the voucher is not persisted.
 *   • Alter re-derives the deduction; cancel reverses the year-to-date state exactly.
 *   • Thresholds are per (deductee, SECTION) — 194J and 194C never see each other.
 *   • 194C's single-bill threshold taxes THAT BILL, not the aggregate. 194Q taxes only the
 *     EXCESS. 194I aggregates PER MONTH and resets when the month does.
 *   • The Deduction Summary reconciles: Σ outstanding == the TDS Payable ledger's closing.
 *   • With TDS switched off (F11), a Payment behaves exactly as it did before Phase 10A.
 */
class ProveTdsCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-tds {--keep : keep the tdstest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the TDS deduction engine: threshold catch-up, Section 206AA, the pre-GST base, server authority, alter/cancel reversal and the summary reconciliation';

    private bool $ok = true;

    private VoucherScreen $screen;

    private TdsService $tds;

    private int $bankId;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'tdstest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'TDS Test Co', 'professional');

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
        $this->info($this->ok ? 'ALL TDS ASSERTIONS PASSED.' : 'TDS ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        // Phase 12A — pin the throwaway tenant's default company as active before
        // any scoped model is touched (CLI has no session).
        if (! $this->resolveActiveCompany()) {
            throw new \RuntimeException('No default company in the throwaway tenant.');
        }

        $this->screen = new VoucherScreen();
        $this->tds = app(TdsService::class);
        $bs = app(BalanceService::class);

        // TDS is orthogonal to the tax regime; GST is switched on too so the pre-GST
        // base rule can be exercised against a real Input IGST duty ledger.
        CompanyFeature::current()->update(['tds' => true, 'gst' => true]);
        activeCompany()->update(['state' => 'Maharashtra', 'gstin' => '27AAAAA0000A1Z5']);
        // The service caches the duty-ledger map on first use; this instance is fresh.
        $this->tds = app(TdsService::class);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');

        $bank = Ledger::create(['name' => 'HDFC Bank', 'group_id' => $gid('Bank Accounts')]);
        $this->bankId = $bank->id;
        $legalFees = Ledger::create(['name' => 'Legal Fees', 'group_id' => $gid('Indirect Expenses')]);
        $contractExp = Ledger::create(['name' => 'Contract Work', 'group_id' => $gid('Indirect Expenses')]);
        $rentExp = Ledger::create(['name' => 'Office Rent', 'group_id' => $gid('Indirect Expenses')]);
        $goodsExp = Ledger::create(['name' => 'Goods Purchased', 'group_id' => $gid('Purchase Accounts')]);
        $consultancy = Ledger::create(['name' => 'Consultancy Fees', 'group_id' => $gid('Indirect Expenses')]);

        $payableId = $this->tds->payableLedgerId();
        $inputIgst = Ledger::where('name', 'Input IGST')->value('id');

        // ═══ 1. The rate table ══════════════════════════════════════════════════
        $this->section('Rate table — Section 393 in force, the 194-series repealed');

        $fy = Voucher::fyStartFor(Carbon::today());
        $this->expect('Today falls in FY 2026-27', Voucher::fyLabel($fy), '2026-27');
        $this->expect('Catalog holds both eras', TdsSection::count(), 15);

        $live = collect($this->tds->sections($fy))->pluck('code')->all();
        sort($live);
        $this->expect('Live sections for FY 2026-27 are the 393-series only', $live, [
            '393-194A', '393-194C', '393-194H', '393-194I-A', '393-194I-B', '393-194J', '393-194Q',
        ]);
        $this->expect('Expired 194J is NOT in the live list', in_array('194J', $live, true), false);
        $this->expect('…but it still exists in the catalog (historical vouchers need it)',
            TdsSection::where('code', '194J')->exists(), true);
        $this->expect('Expired 194J has effective_to in the past',
            (int) TdsSection::where('code', '194J')->value('effective_to') < $fy, true);
        $this->expect('194I-B carries two date ranges (annual → monthly)',
            TdsSection::where('code', '194I-B')->count(), 2);

        $s194J = TdsSection::where('code', '393-194J')->first();
        $s194C = TdsSection::where('code', '393-194C')->first();
        $s194Q = TdsSection::where('code', '393-194Q')->first();
        $s194I = TdsSection::where('code', '393-194I-B')->first();

        $this->expect('393-194J normalises to base code 194J', $s194J->baseCodeValue(), '194J');
        $this->expect('393-194J rate', (float) $s194J->rate, 10.0);
        $this->expect('393-194J annual threshold', (float) $s194J->threshold_annual, 50000.0);
        $this->expect('393-194C individual rate', $s194C->rateFor('individual_huf'), 1.0);
        $this->expect('393-194C company rate', $s194C->rateFor('company_firm_llp'), 2.0);
        $this->expect('393-194Q deducts on the excess', $s194Q->deductsOnExcess(), true);
        $this->expect('393-194I-B aggregates monthly', $s194I->isMonthly(), true);
        $this->expect('TDS Payable is tax_type=tds with a NULL tax_role (invisible to GST)',
            Ledger::whereKey($payableId)->value('tax_type').'/'.(Ledger::whereKey($payableId)->value('tax_role') ?? 'null'),
            'tds/null');

        // ═══ 2. Deductee tagging ════════════════════════════════════════════════
        $this->section('Deductee tagging');

        $advisors = Ledger::create([
            'name' => 'M/s Legal Advisors', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'ABCDE1234F', 'deductee_type' => 'individual_huf',
            'default_tds_section_id' => $s194J->id,
        ]);
        $this->expect('Deductee PAN stored', $advisors->fresh()->deductee_pan, 'ABCDE1234F');
        $this->expect('Deductee type stored', $advisors->fresh()->deductee_type, 'individual_huf');
        $this->expect('Default section stored', (int) $advisors->fresh()->default_tds_section_id, $s194J->id);
        $this->expect('Deductee appears in the screen cache',
            collect($this->tds->deducteeCache())->pluck('id')->contains($advisors->id), true);
        $this->expect('Effective rate with PAN (individual)', $this->tds->effectiveRate($s194J, $advisors->fresh()), 10.0);

        // ═══ 3. Below threshold ═════════════════════════════════════════════════
        $this->section('Below threshold — ₹40,000 of a ₹50,000 annual threshold');

        $v1 = $this->payTds($legalFees, 40000, $advisors, $s194J, '2026-04-10');
        $this->expect('V1 posts exactly two lines (no TDS line)', $v1->entries()->count(), 2);
        $this->expect('V1 Dr Legal Fees', $this->amt($v1, $legalFees->id, 'Dr'), 40000.0);
        $this->expect('V1 Cr Bank (full amount — vendor paid in full)', $this->amt($v1, $this->bankId, 'Cr'), 40000.0);
        $this->expect('V1 has no TDS Payable line', $this->amt($v1, $payableId, 'Cr'), 0.0);
        $this->expect('V1 balanced', $this->balanced($v1), true);
        $this->expectYtd('after V1', $advisors->id, $s194J->id, 40000.0, 0.0);
        $this->expect('V1 still writes a tds_deductions row (the audit trail)',
            TdsDeduction::where('voucher_id', $v1->id)->count(), 1);
        $this->expect('V1 row records 0 deducted',
            (float) TdsDeduction::where('voucher_id', $v1->id)->value('deducted_amount'), 0.0);
        $this->expect('V1 row has no voucher_entry_id (no TDS line to point at)',
            TdsDeduction::where('voucher_id', $v1->id)->value('voucher_entry_id'), null);

        // ═══ 4. THE CATCH-UP — the subtle correctness point ═════════════════════
        $this->section('Threshold crossed — the aggregate catch-up');

        $v2 = $this->payTds($legalFees, 15000, $advisors, $s194J, '2026-04-20');
        $this->expect('V2 posts three lines', $v2->entries()->count(), 3);
        $this->expect('V2 Dr Legal Fees = this payment only', $this->amt($v2, $legalFees->id, 'Dr'), 15000.0);
        $this->expect('V2 Cr TDS Payable = 55,000 × 10% (the WHOLE aggregate)', $this->amt($v2, $payableId, 'Cr'), 5500.0);
        $this->expect('V2 Cr Bank = 15,000 − 5,500 (vendor short-paid by the catch-up)',
            $this->amt($v2, $this->bankId, 'Cr'), 9500.0);
        $this->expect('V2 balanced', $this->balanced($v2), true);
        $this->expectYtd('after V2', $advisors->id, $s194J->id, 55000.0, 5500.0);

        $r2 = TdsDeduction::where('voucher_id', $v2->id)->first();
        $this->expect('V2 row section', TdsSection::find($r2->tds_section_id)->code, '393-194J');
        $this->expect('V2 row base_amount = the aggregate it was computed on', (float) $r2->base_amount, 55000.0);
        $this->expect('V2 row payment_amount = this voucher only', (float) $r2->payment_amount, 15000.0);
        $this->expect('V2 row deducted', (float) $r2->deducted_amount, 5500.0);
        $this->expect('V2 row rate', (float) $r2->rate, 10.0);
        $this->expect('V2 row points at the TDS Payable entry', $r2->voucher_entry_id !== null, true);
        $this->expect('V2 reason names the catch-up', str_contains($r2->reason, 'catching up on 40,000.00'), true);
        $this->expect('deducted == base × rate − already deducted',
            (float) $r2->deducted_amount, round(55000.0 * 0.10, 2) - 0.0);
        $this->expect('The ₹4,000 never withheld on V1 is caught up here',
            round(5500.0 - (15000.0 * 0.10), 2), 4000.0);

        // ═══ 5. Subsequent payment — no catch-up left ═══════════════════════════
        $this->section('Post-crossing payment — deducts on its own base');

        $v3 = $this->payTds($legalFees, 20000, $advisors, $s194J, '2026-05-05');
        $this->expect('V3 posts three lines', $v3->entries()->count(), 3);
        $this->expect('V3 Cr TDS Payable = 20,000 × 10%', $this->amt($v3, $payableId, 'Cr'), 2000.0);
        $this->expect('V3 Cr Bank', $this->amt($v3, $this->bankId, 'Cr'), 18000.0);
        $this->expect('V3 balanced', $this->balanced($v3), true);
        $this->expectYtd('after V3', $advisors->id, $s194J->id, 75000.0, 7500.0);
        $this->expect('Cumulative deducted == 75,000 × 10%', 7500.0, round(75000.0 * 0.10, 2));

        // ═══ 6. Section 206AA — no PAN ══════════════════════════════════════════
        $this->section('Section 206AA — no PAN ⇒ 20%, not the section rate');

        $noPan = Ledger::create([
            'name' => 'M/s Counsel (no PAN)', 'group_id' => $gid('Sundry Creditors'),
            'deductee_type' => 'individual_huf', 'default_tds_section_id' => $s194J->id,
        ]);
        $this->expect('Effective rate without PAN = max(10, 20)', $this->tds->effectiveRate($s194J, $noPan), 20.0);

        $v4 = $this->payTds($legalFees, 60000, $noPan, $s194J, '2026-05-06');
        $this->expect('V4 Cr TDS Payable = 60,000 × 20%', $this->amt($v4, $payableId, 'Cr'), 12000.0);
        $this->expect('V4 Cr Bank', $this->amt($v4, $this->bankId, 'Cr'), 48000.0);
        $this->expect('V4 NOT deducted at the 10% section rate',
            $this->amt($v4, $payableId, 'Cr') === 6000.0, false);
        $this->expect('V4 balanced', $this->balanced($v4), true);
        $this->expect('V4 row rate stored as 20', (float) TdsDeduction::where('voucher_id', $v4->id)->value('rate'), 20.0);
        $this->expect('V4 reason cites Section 206AA',
            str_contains(TdsDeduction::where('voucher_id', $v4->id)->value('reason'), '206AA'), true);
        $this->expect('194Q’s 206AA floor is 5%, not 20% (the proviso — data, not code)',
            $this->tds->effectiveRate($s194Q, $noPan), 5.0);

        // ═══ 7. The pre-GST base ════════════════════════════════════════════════
        $this->section('GST base — TDS falls on the taxable value, not the GST-inclusive dues');

        $gstVendor = Ledger::create([
            'name' => 'M/s GST Consultants', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'PQRSX9876Z', 'deductee_type' => 'company_firm_llp',
            'default_tds_section_id' => $s194J->id, 'state' => 'Karnataka',
        ]);

        // Dr Consultancy 100,000 · Dr Input IGST 18,000 · Cr TDS 10,000 · Cr Bank 108,000
        $v5 = $this->post([
            'type' => 'payment', 'date' => '2026-05-10',
            'lines' => [
                ['ledger_id' => $consultancy->id, 'dr_cr' => 'Dr', 'amount' => 100000],
                ['ledger_id' => $inputIgst, 'dr_cr' => 'Dr', 'amount' => 18000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 10000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 108000],
            ],
            'tds_deduction' => [
                'deductee_ledger_id' => $gstVendor->id,
                'tds_section_id' => $s194J->id,
                'base_amount' => 100000,
            ],
        ]);
        $this->expect('V5 party dues were 1,18,000 but TDS base is 1,00,000',
            (float) TdsDeduction::where('voucher_id', $v5->id)->value('payment_amount'), 100000.0);
        $this->expect('V5 Cr TDS Payable = 1,00,000 × 10% (not 1,18,000 × 10%)',
            $this->amt($v5, $payableId, 'Cr'), 10000.0);
        $this->expect('V5 would have been 11,800 on the GST-inclusive dues',
            $this->amt($v5, $payableId, 'Cr') === 11800.0, false);
        $this->expect('V5 Cr Bank = total debits − TDS', $this->amt($v5, $this->bankId, 'Cr'), 108000.0);
        $this->expect('V5 balanced', $this->balanced($v5), true);
        $this->expect('Server derives the base from the non-duty debits',
            $this->tds->basePaiseFromLines([
                ['ledger_id' => $consultancy->id, 'dr_cr' => 'Dr', 'amount' => 100000],
                ['ledger_id' => $inputIgst, 'dr_cr' => 'Dr', 'amount' => 18000],
            ]), 10000000);

        // A declared base that includes the GST is rejected outright.
        $this->expect('A base of 1,18,000 (GST-inclusive) is rejected', $this->rejects([
            'type' => 'payment', 'date' => '2026-05-11',
            'lines' => [
                ['ledger_id' => $consultancy->id, 'dr_cr' => 'Dr', 'amount' => 100000],
                ['ledger_id' => $inputIgst, 'dr_cr' => 'Dr', 'amount' => 18000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 11800],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 106200],
            ],
            'tds_deduction' => ['deductee_ledger_id' => $gstVendor->id, 'tds_section_id' => $s194J->id, 'base_amount' => 118000],
        ], 'tds'), true);

        // ═══ 8. Server authority — a tampered TDS amount ════════════════════════
        $this->section('Server authority — a tampered TDS amount is rejected');

        $before = Voucher::count();
        // Correct base, WRONG TDS (3,000 instead of 2,000), but Dr==Cr — so only the TDS
        // authority can catch it, never the balance gate.
        $rejected = $this->rejects([
            'type' => 'payment', 'date' => '2026-05-12',
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 20000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 3000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 17000],
            ],
            'tds_deduction' => ['deductee_ledger_id' => $advisors->id, 'tds_section_id' => $s194J->id, 'base_amount' => 20000],
        ], 'tds', 'balance');
        $this->expect('Tampered TDS rejected by the TDS authority (not the balance gate)', $rejected, true);
        $this->expect('…and the voucher was NOT persisted', Voucher::count(), $before);
        $this->expectYtd('unchanged after the rejection', $advisors->id, $s194J->id, 75000.0, 7500.0);

        $this->expect('A TDS Payable credit with no declaration is rejected', $this->rejects([
            'type' => 'payment', 'date' => '2026-05-12',
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 20000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 2000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 18000],
            ],
        ], 'tds'), true);

        $this->expect('A repealed section on a current-year voucher is rejected', $this->rejects([
            'type' => 'payment', 'date' => '2026-05-12',
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 20000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 20000],
            ],
            'tds_deduction' => [
                'deductee_ledger_id' => $advisors->id,
                'tds_section_id' => TdsSection::where('code', '194J')->value('id'),
                'base_amount' => 20000,
            ],
        ], 'tds'), true);

        $this->expect('TDS on a Sales voucher is rejected', $this->rejects([
            'type' => 'journal', 'date' => '2026-05-12',
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 20000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 20000],
            ],
            'tds_deduction' => ['deductee_ledger_id' => $advisors->id, 'tds_section_id' => $s194J->id, 'base_amount' => 20000],
        ], 'tds'), true);

        // ═══ 9. Alter ═══════════════════════════════════════════════════════════
        $this->section('Alter — the deduction is re-derived from a state without this voucher');

        // V3 was 20,000 → 2,000. Re-post it at 30,000: prior becomes 55,000/5,500, the new
        // aggregate is 85,000 ⇒ 8,500 due − 5,500 already withheld ⇒ 3,000 on this voucher.
        $v3b = $this->post([
            'type' => 'payment', 'date' => '2026-05-05', 'voucher_id' => $v3->id,
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 30000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 3000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 27000],
            ],
            'tds_deduction' => ['deductee_ledger_id' => $advisors->id, 'tds_section_id' => $s194J->id, 'base_amount' => 30000],
        ]);
        $this->expect('V3 altered 20,000 → 30,000, TDS re-derived to 3,000',
            $this->amt($v3b, $payableId, 'Cr'), 3000.0);
        $this->expect('V3 altered Cr Bank', $this->amt($v3b, $this->bankId, 'Cr'), 27000.0);
        $this->expect('V3 altered balanced', $this->balanced($v3b), true);
        $this->expectYtd('after altering V3', $advisors->id, $s194J->id, 85000.0, 8500.0);
        $this->expect('Cumulative deducted still == aggregate × 10%', 8500.0, round(85000.0 * 0.10, 2));
        $this->expect('Exactly one deduction row survives the alter',
            TdsDeduction::where('voucher_id', $v3->id)->count(), 1);
        $this->expect('An alter posting a stale TDS amount is rejected', $this->rejects([
            'type' => 'payment', 'date' => '2026-05-05', 'voucher_id' => $v3->id,
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 30000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 2000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 28000],
            ],
            'tds_deduction' => ['deductee_ledger_id' => $advisors->id, 'tds_section_id' => $s194J->id, 'base_amount' => 30000],
        ], 'tds'), true);

        // ═══ 10. Cancel ═════════════════════════════════════════════════════════
        $this->section('Cancel — the year-to-date state is reversed exactly');

        $screen = new VoucherScreen();
        $screen->editVoucherId = $v3->id;
        $cancel = $screen->cancelVoucher();
        $this->expect('V3 cancelled', $cancel['ok'], true);
        $this->expect('V3 deduction row deleted', TdsDeduction::where('voucher_id', $v3->id)->count(), 0);
        $this->expectYtd('after cancelling V3', $advisors->id, $s194J->id, 55000.0, 5500.0);
        $this->expect('V3 voucher entries gone', Voucher::find($v3->id), null);

        // ═══ 11. Per-section thresholds ═════════════════════════════════════════
        $this->section('Thresholds are per (deductee, SECTION) — 194J and 194C never mix');

        // The same vendor, now paid under a contract. 194C's aggregate starts at zero even
        // though the vendor is already 55,000 deep under 194J.
        $c1 = $this->payTds($contractExp, 25000, $advisors, $s194C, '2026-06-01');
        $this->expect('C1 under 393-194C: 25,000 ≤ 30,000 single and ≤ 1,00,000 annual ⇒ no TDS',
            $this->amt($c1, $payableId, 'Cr'), 0.0);
        $this->expect('C1 posts two lines', $c1->entries()->count(), 2);
        $this->expectYtd('194C state after C1', $advisors->id, $s194C->id, 25000.0, 0.0);
        $this->expectYtd('194J state untouched by the 194C payment', $advisors->id, $s194J->id, 55000.0, 5500.0);
        $this->expect('Two independent YTD rows for one deductee',
            TdsDeducteeYtd::where('deductee_ledger_id', $advisors->id)->count(), 2);

        // Crossing 194C's ANNUAL threshold: 25,000 + 80,000 = 1,05,000 ⇒ 1% of the whole.
        $c2 = $this->payTds($contractExp, 80000, $advisors, $s194C, '2026-06-02');
        $this->expect('C2 crosses the 1,00,000 annual threshold ⇒ 1% of 1,05,000',
            $this->amt($c2, $payableId, 'Cr'), 1050.0);
        $this->expect('C2 Cr Bank', $this->amt($c2, $this->bankId, 'Cr'), 78950.0);
        $this->expectYtd('194C state after C2', $advisors->id, $s194C->id, 105000.0, 1050.0);
        $this->expectYtd('194J state STILL untouched', $advisors->id, $s194J->id, 55000.0, 5500.0);

        // ═══ 12. 194C's single-bill threshold ═══════════════════════════════════
        $this->section('194C single-bill threshold taxes THAT BILL, not the aggregate');

        $buildco = Ledger::create([
            'name' => 'M/s BuildCo', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'BUILD1234C', 'deductee_type' => 'individual_huf',
            'default_tds_section_id' => $s194C->id,
        ]);
        $b1 = $this->payTds($contractExp, 20000, $buildco, $s194C, '2026-06-03');
        $this->expect('B1 20,000: under both thresholds ⇒ no TDS', $this->amt($b1, $payableId, 'Cr'), 0.0);

        $b2 = $this->payTds($contractExp, 35000, $buildco, $s194C, '2026-06-04');
        $this->expect('B2 35,000 > the 30,000 single threshold ⇒ 1% of 35,000 = 350',
            $this->amt($b2, $payableId, 'Cr'), 350.0);
        $this->expect('…NOT 1% of the 55,000 aggregate (the small bill stays untaxed)',
            $this->amt($b2, $payableId, 'Cr') === 550.0, false);
        $this->expectYtd('BuildCo 194C state', $buildco->id, $s194C->id, 55000.0, 350.0);

        // Now cross the ANNUAL threshold too: the earlier 20,000 is finally caught up.
        $b3 = $this->payTds($contractExp, 50000, $buildco, $s194C, '2026-06-05');
        $this->expect('B3 aggregate 1,05,000 crosses the annual threshold ⇒ 1,050 − 350 = 700',
            $this->amt($b3, $payableId, 'Cr'), 700.0);
        $this->expectYtd('BuildCo 194C state after B3', $buildco->id, $s194C->id, 105000.0, 1050.0);
        $this->expect('Cumulative == 1% of the whole aggregate', 1050.0, round(105000.0 * 0.01, 2));

        // ═══ 13. 194Q — the excess, not the aggregate ═══════════════════════════
        $this->section('194Q deducts on the EXCESS over the threshold');

        $supplier = Ledger::create([
            'name' => 'M/s Goods Supplier', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'GOODS1234S', 'deductee_type' => 'company_firm_llp',
            'default_tds_section_id' => $s194Q->id,
        ]);
        $q1 = $this->payTds($goodsExp, 5200000, $supplier, $s194Q, '2026-06-10');
        $this->expect('Q1 52,00,000: 0.1% of the 2,00,000 excess = 200',
            $this->amt($q1, $payableId, 'Cr'), 200.0);
        $this->expect('…NOT 0.1% of the whole 52,00,000', $this->amt($q1, $payableId, 'Cr') === 5200.0, false);
        $this->expect('Q1 row base_amount = the excess', (float) TdsDeduction::where('voucher_id', $q1->id)->value('base_amount'), 200000.0);
        $this->expect('Q1 row payment_amount = the payment', (float) TdsDeduction::where('voucher_id', $q1->id)->value('payment_amount'), 5200000.0);

        $q2 = $this->payTds($goodsExp, 100000, $supplier, $s194Q, '2026-06-11');
        $this->expect('Q2 adds 1,00,000: excess 3,00,000 ⇒ 300 due − 200 withheld = 100',
            $this->amt($q2, $payableId, 'Cr'), 100.0);

        // ═══ 14. 194I — a monthly window that resets ════════════════════════════
        $this->section('194I aggregates PER MONTH and resets when the month does');

        $landlord = Ledger::create([
            'name' => 'M/s Landlord', 'group_id' => $gid('Sundry Creditors'),
            'deductee_pan' => 'RENTA1234L', 'deductee_type' => 'individual_huf',
            'default_tds_section_id' => $s194I->id,
        ]);
        $r1 = $this->payTds($rentExp, 30000, $landlord, $s194I, '2026-04-05');
        $this->expect('R1 Apr 30,000 ≤ the 50,000 monthly threshold ⇒ no TDS', $this->amt($r1, $payableId, 'Cr'), 0.0);

        $r2v = $this->payTds($rentExp, 30000, $landlord, $s194I, '2026-04-20');
        $this->expect('R2 Apr aggregate 60,000 crosses ⇒ 10% of 60,000 = 6,000',
            $this->amt($r2v, $payableId, 'Cr'), 6000.0);

        $r3 = $this->payTds($rentExp, 30000, $landlord, $s194I, '2026-05-05');
        $this->expect('R3 MAY is a new window ⇒ 30,000 is below threshold again, no TDS',
            $this->amt($r3, $payableId, 'Cr'), 0.0);
        $this->expect('…even though the YEAR’s total is now 90,000',
            (float) TdsDeducteeYtd::where('deductee_ledger_id', $landlord->id)
                ->where('tds_section_id', $s194I->id)->value('paid_amount'), 90000.0);
        $this->expectYtd('Landlord 194I fiscal-year state', $landlord->id, $s194I->id, 90000.0, 6000.0);

        // ═══ 15. Trial Balance ══════════════════════════════════════════════════
        $this->section('The Trial Balance still balances');

        [$f, $t] = $bs->withinFy(null, null);
        $tb = $bs->trialBalance($f, $t);
        $this->expect('TB Dr == Cr', $tb['balanced'], true);
        $this->expect('TB totals non-zero', $tb['total_dr'] > 0, true);

        // ═══ 16. Deduction Summary + reconciliation ═════════════════════════════
        $this->section('Deduction Summary — and Σ outstanding == TDS Payable closing');

        $sum = $this->tds->summary($f, $t);
        $bySection = [];
        foreach ($sum['rows'] as $row) {
            if ($row['kind'] === 'subtotal') {
                continue;
            }
            if ($row['kind'] === 'section') {
                $bySection[$row['label']] = $row;
            }
        }
        $this->expect('Summary groups by section', array_keys($bySection), ['393-194C', '393-194I-B', '393-194J', '393-194Q']);
        $this->expect('393-194J deducted (V2 5,500 + V4 12,000 + V5 10,000)', $bySection['393-194J']['deducted'], '27,500.00');
        $this->expect('393-194C deducted (1,050 + 1,050)', $bySection['393-194C']['deducted'], '2,100.00');
        $this->expect('393-194Q deducted (200 + 100)', $bySection['393-194Q']['deducted'], '300.00');
        $this->expect('393-194I-B deducted', $bySection['393-194I-B']['deducted'], '6,000.00');

        $expectedTotal = 27500.0 + 2100.0 + 300.0 + 6000.0;
        $this->expect('Total deducted', $sum['total_deducted_paise'], (int) round($expectedTotal * 100));
        $this->expect('Nothing remitted yet', $sum['remitted_paise'], 0);
        $this->expect('Σ row outstanding == total deducted', $sum['total_outstanding_paise'], $sum['total_deducted_paise']);
        $this->expect('THE RECONCILIATION: Σ outstanding == TDS Payable ledger closing',
            $sum['total_outstanding_paise'], $sum['payable_closing_paise']);

        $drill = $this->tds->deducteeVouchers($s194J->id, $advisors->id, $f, $t);
        $this->expect('Drill to Legal Advisors under 393-194J finds both payments', count($drill), 2);
        $this->expect('Drill row 1 deducted 0.00', $drill[0]['deducted'], '0.00');
        $this->expect('Drill row 2 deducted 5,500.00', $drill[1]['deducted'], '5,500.00');
        $this->expect('Drill row 2 shows the aggregate base', $drill[1]['base'], '55,000.00');

        // ═══ 17. Remittance — a plain Payment against TDS Payable ═══════════════
        $this->section('Remittance — an ordinary Payment discharges the liability, FIFO');

        $rem = $this->post([
            'type' => 'payment', 'date' => '2026-06-30',
            'lines' => [
                ['ledger_id' => $payableId, 'dr_cr' => 'Dr', 'amount' => 5500],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 5500],
            ],
        ]);
        $this->expect('Remittance needs no special voucher type', $rem->type, 'payment');
        $this->expect('Remittance writes no deduction row', TdsDeduction::where('voucher_id', $rem->id)->count(), 0);

        $sum2 = $this->tds->summary($f, $t);
        $this->expect('Remitted in period', $sum2['remitted_paise'], 550000);
        $this->expect('Total outstanding drops by the remittance',
            $sum2['total_outstanding_paise'], $sum['total_outstanding_paise'] - 550000);
        $this->expect('Reconciliation STILL holds after the remittance',
            $sum2['total_outstanding_paise'], $sum2['payable_closing_paise']);

        // FIFO: the oldest deduction (V2's 5,500, dated 2026-04-20) is discharged first.
        $j = null;
        foreach ($sum2['rows'] as $row) {
            if ($row['kind'] === 'deductee' && $row['label'] === 'M/s Legal Advisors' && $row['rate'] === '10%') {
                $j = $row;
            }
        }
        $this->expect('FIFO cleared Legal Advisors’ 393-194J deduction entirely', $j['outstanding'], '0.00');
        $this->expect('…while its deducted figure is unchanged', $j['deducted'], '5,500.00');

        // ═══ 18. TDS off ⇒ Phase 8B behaviour, exactly ═════════════════════════
        $this->section('TDS disabled (F11) — a Payment behaves exactly as before');

        CompanyFeature::current()->update(['tds' => false]);
        $freshTds = app(TdsService::class);
        $this->expect('Service reports disabled', $freshTds->enabled(), false);

        $rowsBefore = TdsDeduction::count();
        $v6 = $this->post([
            'type' => 'payment', 'date' => '2026-07-01',
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 9000],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 9000],
            ],
            // Even a declaration is ignored while the feature is off.
            'tds_deduction' => ['deductee_ledger_id' => $advisors->id, 'tds_section_id' => $s194J->id, 'base_amount' => 9000],
        ]);
        $this->expect('Payment posts two lines', $v6->entries()->count(), 2);
        $this->expect('No deduction row written', TdsDeduction::count(), $rowsBefore);
        $this->expectYtd('YTD untouched while TDS is off', $advisors->id, $s194J->id, 55000.0, 5500.0);
        $this->expect('A TDS Payable credit is rejected while TDS is off', $this->rejects([
            'type' => 'payment', 'date' => '2026-07-01',
            'lines' => [
                ['ledger_id' => $legalFees->id, 'dr_cr' => 'Dr', 'amount' => 9000],
                ['ledger_id' => $payableId, 'dr_cr' => 'Cr', 'amount' => 900],
                ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => 8100],
            ],
        ], 'tds'), true);
        $this->expect('bootData ships no TDS payload when off',
            (new VoucherScreen())->bootData()['tdsEnabled'], false);

        $tb2 = $bs->trialBalance($f, $t);
        $this->expect('TB still balances at the end', $tb2['balanced'], true);
    }

    // ---- posting helpers ------------------------------------------------------

    /**
     * Post a TDS-deducting Payment the way the screen does: the user enters the gross
     * expense and the bank; the deduction is derived and the bank leg reduced by it. The
     * server independently recomputes the number this helper puts on the TDS line.
     */
    private function payTds(Ledger $expense, float $base, Ledger $deductee, TdsSection $section, string $date): Voucher
    {
        $fy = Voucher::fyStartFor(Carbon::parse($date));
        [$deducted] = $this->tds->computeDeduction(
            $deductee->id, $section->id, (int) round($base * 100), $fy, null, Carbon::parse($date),
        );
        $tds = round($deducted / 100, 2);

        $lines = [['ledger_id' => $expense->id, 'dr_cr' => 'Dr', 'amount' => $base]];
        if ($tds > 0) {
            $lines[] = ['ledger_id' => $this->tds->payableLedgerId(), 'dr_cr' => 'Cr', 'amount' => $tds];
        }
        $lines[] = ['ledger_id' => $this->bankId, 'dr_cr' => 'Cr', 'amount' => round($base - $tds, 2)];

        return $this->post([
            'type' => 'payment', 'date' => $date, 'lines' => $lines,
            'tds_deduction' => [
                'deductee_ledger_id' => $deductee->id,
                'tds_section_id' => $section->id,
                'base_amount' => $base,
            ],
        ]);
    }

    private function post(array $payload): Voucher
    {
        $res = $this->screen->post($payload);

        return Voucher::with('entries')->find($res['voucher']['id']);
    }

    /** True when the payload is rejected, by $key and NOT by $notKey. */
    private function rejects(array $payload, string $key, ?string $notKey = null): bool
    {
        try {
            $this->screen->post($payload);
        } catch (ValidationException $e) {
            $keys = array_keys($e->errors());

            return in_array($key, $keys, true) && ($notKey === null || ! in_array($notKey, $keys, true));
        }

        return false;
    }

    private function amt(Voucher $v, ?int $ledgerId, string $side): float
    {
        return (float) $v->fresh('entries')->entries
            ->where('ledger_id', $ledgerId)->where('dr_cr', $side)->sum('amount');
    }

    private function balanced(Voucher $v): bool
    {
        $e = $v->fresh('entries')->entries;
        $dr = (int) round($e->where('dr_cr', 'Dr')->sum('amount') * 100);
        $cr = (int) round($e->where('dr_cr', 'Cr')->sum('amount') * 100);

        return $dr === $cr && $dr > 0;
    }

    private function expectYtd(string $when, int $deducteeId, int $sectionId, float $paid, float $deducted): void
    {
        $row = TdsDeducteeYtd::where('deductee_ledger_id', $deducteeId)->where('tds_section_id', $sectionId)->first();
        $this->expect("YTD paid {$when}", (float) ($row->paid_amount ?? 0), $paid);
        $this->expect("YTD deducted {$when}", (float) ($row->deducted_amount ?? 0), $deducted);
    }

    // ---- reporting helpers ----------------------------------------------------

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
            return '['.implode(', ', array_map(fn ($x) => (string) $x, $v)).']';
        }

        return (string) $v;
    }
}
