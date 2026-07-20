<?php

namespace App\Services\Vat;

use App\Models\CompanyFeature;
use App\Models\Voucher;
use App\Services\VatService;
use App\Support\NepalDate;
use RuntimeException;

/**
 * Phase 9B — the Nepal VAT return (अनुसूची-१० / Schedule 10 of the VAT Rules, 2053).
 *
 * Built box-for-box against the Government's own form, read directly from
 * `_docs/ird-schemas/vat-rules-2053-schedule10.pdf` (pages 70–73) and catalogued in
 * `_docs/ird-schemas/nepal-vat-return-fields.json`. Nothing here is from recollection.
 *
 * THE FILING CHANNEL IS A WEB FORM. IRD publishes no upload schema for the VAT return
 * and the taxpayer portal accepts no return file — so this service produces a
 * **transcription document**: every box of Schedule 10, in the form's own order, with
 * the form's own Devanagari label, ready to be keyed into the portal without any
 * re-computation. (The sales/purchase register annexure is a separate Excel upload
 * whose column schema could not be verified; it is deliberately NOT produced here.)
 *
 * Read-only: it touches Voucher / VoucherEntry / Ledger / CompanyFeature and mutates
 * none of them. The tax math is not re-derived — it is the existing
 * {@see VatService::summary()} aggregation, mapped onto the form's box numbers.
 *
 * DIRECTION (the crux, mirroring Phase 8A/9A):
 *   • `credit_note`  = sales return  → debits Output VAT → reduces box 1.1's debit column.
 *   • `debit_note`   = purchase return → credits Input VAT → reduces box 2.1's credit column.
 *   A Debit Note therefore reduces INPUT VAT, never output VAT.
 *
 * Phase 8B inventory-workflow vouchers carry no tax and no ledger entries, so they
 * cannot reach the return: only {@see self::TAX_VOUCHER_TYPES} are ever read.
 */
class NepalVatReturnService
{
    /** The only voucher types that bear VAT. Workflow/stock vouchers are excluded by construction. */
    public const TAX_VOUCHER_TYPES = ['sales', 'purchase', 'credit_note', 'debit_note'];

    public function __construct(private VatService $vat) {}

    // ── gates ───────────────────────────────────────────────────────────────

    /**
     * GST and VAT are mutually exclusive (Phase 5E). The Nepal VAT return only exists
     * for a VAT-regime tenant; a GST tenant must not be able to generate one.
     */
    public function assertRegime(): void
    {
        $f = CompanyFeature::current();

        if (! $f->vat && ! $f->gst) {
            throw new RuntimeException('No tax regime is configured — set your tax regime in F11 Features before generating returns');
        }
        if (! $f->vat) {
            throw new RuntimeException('This tenant is under GST regime, not Nepal VAT — generate GSTR-1 / GSTR-3B instead');
        }
    }

    /** The taxpayer's PAN — the 9-digit registration number in box करदाता दर्ता नम्बर. */
    public function taxpayerPan(): string
    {
        $pan = trim((string) activeCompany()?->pan);
        if ($pan === '') {
            throw new RuntimeException('Company PAN not configured — set it in F11 Features before generating the VAT return');
        }

        return $pan;
    }

    // ── the return ──────────────────────────────────────────────────────────

    /**
     * The complete Schedule 10 document for a BS period (`YYYY-MM`, e.g. `2082-04`).
     *
     * @param  int  $carryForwardCredit  box 6 — the credit left unadjusted from the
     *   previous return, in whole rupees. ZeroBook does not mirror the IRD credit
     *   ledger (and whether prior excess credit was carried forward or refunded is a
     *   taxpayer decision), so this is an explicit input rather than a silent guess.
     */
    public function return(string $period, int $carryForwardCredit = 0): array
    {
        $this->assertRegime();
        $pan = $this->taxpayerPan();

        [$bsYear, $bsMonth] = NepalDate::assertPeriod($period);
        [$from, $to] = NepalDate::periodRange($period);

        if (\App\Models\Voucher::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNotNull('scenario_id')->exists()) {
            throw new \RuntimeException('Scenario vouchers exist in this period. Return files must use actuals only.');
        }

        $summary = $this->vat->summary($from, $to);

        $sales = $this->buildSales($summary);
        $purchases = $this->buildPurchases($summary);
        $adjustments = $this->buildAdjustments();
        $net = $this->buildNetVat($sales, $purchases, $adjustments, $carryForwardCredit);
        $documents = $this->buildDocumentCounts($period);

        return [
            'form' => [
                'id' => 'अनुसूची-१०',
                'id_en' => 'Schedule 10',
                'title_np' => 'मूल्य अभिवृद्धि कर विवरण फाराम',
                'title_en' => 'Value Added Tax Return Form',
                'legal_basis_np' => 'नियम २६ को उपनियम (१) सँग सम्बन्धित',
                'issuer_np' => 'नेपाल सरकार / अर्थ मन्त्रालय / आन्तरिक राजस्व विभाग',
                'filing_channel' => 'web-form',
            ],
            'header' => [
                'pan' => $pan,
                'pan_np_label' => 'करदाता दर्ता नम्बर',
                'period' => $period,
                'year_bs' => $bsYear,
                'month_bs' => $bsMonth,
                'month_np' => NepalDate::monthNameNp($bsMonth),
                'month_en' => NepalDate::monthNameEn($bsMonth),
                'fiscal_month_index' => NepalDate::fiscalMonthIndex($bsMonth),
                'fiscal_year' => NepalDate::fiscalYearLabel($bsYear, $bsMonth),
                'filing_frequency_np' => 'मासिक',
                'filing_frequency_en' => 'Monthly',
                'gregorian_from' => $from->toDateString(),
                'gregorian_to' => $to->toDateString(),
                // Not stored per-tenant — see _meta.not_tracked_by_zerobook.
                'name' => null,
                'address' => null,
                'telephone' => null,
                'mobile' => null,
            ],
            'boxes' => $sales + $purchases + $adjustments + $net + $documents,
            'columns' => [
                'value' => ['np' => 'कारोवार मूल्य', 'en' => 'Transaction value'],
                'credit' => ['np' => 'खरिदमा तिरेको कर क्रेडिट', 'en' => 'Tax credit paid on purchase (input VAT)'],
                'debit' => ['np' => 'विक्रीमा संकलन गरेको कर डेविट', 'en' => 'Tax collected on sale (output VAT)'],
            ],
        ];
    }

    /**
     * Boxes 1.1–1.3 (विक्री / Sales), page 71.
     *
     * `taxable_sales` is the net of the Sales Accounts group, so a Credit Note (whose
     * Sales Return ledger lives in that group) has already reduced it. Likewise the
     * output VAT is net of the Credit Note's reversal.
     */
    public function buildSales(array $summary): array
    {
        return [
            '1' => ['np' => 'विक्री', 'en' => 'Sales', 'type' => 'section'],
            '1.1' => [
                'np' => 'कर लाग्ने विक्री', 'en' => 'Taxable sales', 'type' => 'row',
                'value' => IrdFormat::rupeesFromPaise($summary['taxable_sales']),
                'debit' => IrdFormat::rupeesFromPaise($summary['output']),
            ],
            // ZeroBook does not classify a sale as an export or as exempt.
            '1.2' => ['np' => 'निर्यात', 'en' => 'Export', 'type' => 'row', 'value' => 0],
            '1.3' => ['np' => 'छुट विक्री', 'en' => 'Exempt sales', 'type' => 'row', 'value' => 0],
        ];
    }

    /**
     * Boxes 2.1–2.4 (खरिद।पैठारी / Purchase · Import), page 71.
     *
     * A Debit Note (purchase return) credits the Input VAT ledger, so it has already
     * reduced `input` — the return's credit column falls, never the debit column.
     */
    public function buildPurchases(array $summary): array
    {
        return [
            '2' => ['np' => 'खरिद।पैठारी', 'en' => 'Purchase / Import', 'type' => 'section'],
            '2.1' => [
                'np' => 'कर लाग्ने खरिद', 'en' => 'Taxable purchase', 'type' => 'row',
                'value' => IrdFormat::rupeesFromPaise($summary['taxable_purchase']),
                'credit' => IrdFormat::rupeesFromPaise($summary['input']),
            ],
            // Imports are not distinguished from domestic purchases; nothing is exempt-flagged.
            '2.2' => ['np' => 'कर लाग्ने पैठारी', 'en' => 'Taxable import', 'type' => 'row', 'value' => 0, 'credit' => 0],
            '2.3' => ['np' => 'छुट खरिद', 'en' => 'Exempt purchase', 'type' => 'row', 'value' => 0],
            '2.4' => ['np' => 'छुट पैठारी', 'en' => 'Exempt import', 'type' => 'row', 'value' => 0],
        ];
    }

    /** Box 3.1 (अन्य थपघट / Other adjustments), page 71. Not modelled by ZeroBook. */
    public function buildAdjustments(): array
    {
        return [
            '3' => ['np' => 'अन्य', 'en' => 'Other', 'type' => 'section'],
            '3.1' => ['np' => 'अन्य थपघट', 'en' => 'Other adjustments', 'type' => 'row', 'credit' => 0, 'debit' => 0],
        ];
    }

    /**
     * Boxes 4–10, pages 71–72.
     *
     *   4 जम्मा            = column totals
     *   5 डेविट—क्रेडिट     = box4.debit − box4.credit          (+ payable / − credit)
     *   6 गत महिनाको …     = carry-forward credit (an input)
     *   7 कुल तिर्नु पर्ने कर = box5 − box6                       (+ payable / − credit)
     *   8/9/10             = refund claim, its basis, and the payment voucher — taxpayer
     *                        actions taken on the portal, so left at zero / unticked.
     */
    public function buildNetVat(array $sales, array $purchases, array $adjustments, int $carryForwardCredit): array
    {
        $totalCredit = ($purchases['2.1']['credit'] ?? 0) + ($purchases['2.2']['credit'] ?? 0) + ($adjustments['3.1']['credit'] ?? 0);
        $totalDebit = ($sales['1.1']['debit'] ?? 0) + ($adjustments['3.1']['debit'] ?? 0);

        $box5 = $totalDebit - $totalCredit;
        $box7 = $box5 - $carryForwardCredit;

        return [
            '4' => ['np' => 'जम्मा', 'en' => 'Total', 'type' => 'total', 'credit' => $totalCredit, 'debit' => $totalDebit],
            '5' => ['np' => 'डेविट—क्रेडिट', 'en' => 'Debit − Credit', 'type' => 'signed', 'amount' => $box5, 'sign' => IrdFormat::sign($box5)],
            '6' => ['np' => 'गत महिनाको मिलान गर्न बाँकी क्रेडिट', 'en' => 'Credit remaining to be adjusted from last month', 'type' => 'input', 'amount' => IrdFormat::count($carryForwardCredit)],
            '7' => ['np' => 'कुल तिर्नु पर्ने कर रु. (५—६)', 'en' => 'Total tax payable Rs. (5 − 6)', 'type' => 'signed', 'amount' => $box7, 'sign' => IrdFormat::sign($box7)],
            '8' => ['np' => 'कर फिर्ता माग गरिएको रकम', 'en' => 'Refund amount claimed', 'type' => 'input', 'amount' => 0],
            '9' => [
                'np' => 'कर फिर्ता मागको आधार', 'en' => 'Basis of refund claim', 'type' => 'tick_one', 'selected' => null,
                'options' => [
                    'unadjusted_four_months' => ['np' => 'लगातार चार महिनासम्म मिलान गर्दा पनि मिलान नभएको', 'en' => 'Not adjusted even after four consecutive months'],
                    'regular_exporter' => ['np' => 'नियमित निर्यातकर्ता', 'en' => 'Regular exporter'],
                    'excess_deposit_four_months' => ['np' => 'लगातार चार महिनासम्म मिलान नभएको बढी दाखिला मूल्य अभिवृद्धि कर', 'en' => 'Excess VAT deposited, unadjusted for four consecutive months'],
                    'other' => ['np' => 'अन्य', 'en' => 'Other'],
                ],
            ],
            '10' => ['np' => 'जम्मा भुक्तानी रु.', 'en' => 'Total payment Rs.', 'type' => 'input', 'amount' => 0, 'voucher_no' => null, 'voucher_no_np' => 'भौचर नं.'],
        ];
    }

    /**
     * Box 11 (कर अवधिमा प्रयोग गरिएका कागजातहरुको विवरण), page 72 — how many of each
     * document the taxpayer issued/received in the period. Counted straight off the
     * voucher table; a Sales Order or Delivery Note is not one of these types, so a
     * Phase 8B workflow voucher can never be counted here.
     */
    public function buildDocumentCounts(string $period): array
    {
        [$from, $to] = NepalDate::periodRange($period);
        $count = fn (string $type) => Voucher::where('type', $type)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('vouchers.scenario_id')
            ->count();

        return [
            '11' => [
                'np' => 'कर अवधिमा प्रयोग गरिएका कागजातहरुको विवरण',
                'en' => 'Details of documents used in the tax period',
                'type' => 'counts',
                'rows' => [
                    'purchase_invoices' => ['np' => 'कुल खरिद बिजक संख्या', 'en' => 'Total purchase invoice count', 'count' => IrdFormat::count($count('purchase'))],
                    'credit_notes' => ['np' => 'क्रेडिट नोट संख्या', 'en' => 'Credit note count', 'count' => IrdFormat::count($count('credit_note'))],
                    'debit_notes' => ['np' => 'डेविट नोट संख्या', 'en' => 'Debit note count', 'count' => IrdFormat::count($count('debit_note'))],
                    // ZeroBook has no "advice" document type.
                    'credit_advices' => ['np' => 'क्रेडिट एडभाइस संख्या', 'en' => 'Credit advice count', 'count' => 0],
                    'debit_advices' => ['np' => 'डेविट एडभाइस संख्या', 'en' => 'Debit advice count', 'count' => 0],
                    'sales_invoices' => ['np' => 'विक्री बिजक जम्मा संख्या', 'en' => 'Total sales invoice count', 'count' => IrdFormat::count($count('sales'))],
                ],
            ],
        ];
    }

    // ── preview ─────────────────────────────────────────────────────────────

    /**
     * The figures a taxpayer eyeballs before transcribing, computed **from the generated
     * return itself** — so the preview can never drift from the document it previews.
     */
    public function preview(string $period, int $carryForwardCredit = 0): array
    {
        $r = $this->return($period, $carryForwardCredit);
        $b = $r['boxes'];

        return [
            'period' => $period,
            'period_label' => NepalDate::periodLabel($period),
            'fiscal_year' => $r['header']['fiscal_year'],
            'gregorian_from' => $r['header']['gregorian_from'],
            'gregorian_to' => $r['header']['gregorian_to'],
            'pan' => $r['header']['pan'],
            'taxable_sales' => $b['1.1']['value'],
            'output_vat' => $b['1.1']['debit'],
            'taxable_purchase' => $b['2.1']['value'],
            'input_vat' => $b['2.1']['credit'],
            'total_credit' => $b['4']['credit'],
            'total_debit' => $b['4']['debit'],
            'debit_minus_credit' => $b['5']['amount'],
            'carry_forward' => $b['6']['amount'],
            'net_vat' => $b['7']['amount'],
            'is_payable' => $b['7']['amount'] >= 0,
            'documents' => array_map(fn ($row) => $row['count'], $b['11']['rows']),
        ];
    }
}
