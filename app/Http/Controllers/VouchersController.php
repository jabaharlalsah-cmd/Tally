<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Support\Money;
use App\Support\Shell;

class VouchersController extends Controller
{
    public function create(string $type = 'payment')
    {
        if (! array_key_exists($type, Voucher::TYPES)) {
            $type = 'payment';
        }

        return view('vouchers.entry', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'type' => $type,
            'voucher' => null,
            'region' => 'Vouchers · '.Voucher::TYPES[$type]['label'],
        ]);
    }

    public function alter(Voucher $voucher)
    {
        return view('vouchers.entry', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'type' => $voucher->type,
            'voucher' => $voucher,
            'region' => 'Vouchers · Alter',
        ]);
    }

    /**
     * Printable invoice / voucher — a standalone (non-shell) page with its own
     * print CSS. For a Sales/Purchase invoice it renders the party header + the
     * ledger allocation lines; for any other voucher it renders the double-entry
     * lines. Amount in words uses the Indian lakh/crore format.
     */
    public function print(Voucher $voucher)
    {
        $voucher->load(['entries.ledger', 'partyLedger.group', 'stockEntries.stockItem.unit', 'stockEntries.godown']);
        $company = Shell::config();

        // Phase 6C stock vouchers are internal documents (no customer, no ledger
        // side) — a dedicated light movement slip. Cost is shown here because there
        // is no customer to shield it from (unlike a sales invoice).
        if (in_array($voucher->type, Voucher::STOCK_TYPES, true)) {
            return view('vouchers.print-stock', [
                'voucher' => $voucher,
                'company' => $company,
                'documentTitle' => $voucher->type === 'stock_journal' ? 'Stock Journal' : 'Physical Stock',
                'summary' => $voucher->stockSummary(),
                'rows' => $voucher->stockEntries->map(fn ($s) => [
                    'item' => $s->stockItem?->name ?? '(deleted item)',
                    'unit' => $s->stockItem?->unit?->symbol,
                    'godown' => $s->godown?->name ?? '—',
                    'direction' => $s->direction,
                    'qty' => (float) $s->quantity,
                    'rate' => (float) $s->rate,
                    'value' => (float) $s->value,
                ])->values(),
            ]);
        }

        // Phase 8B — inventory-workflow vouchers (Orders, Delivery/Receipt Notes,
        // Rejections) print as light commitment/stock documents: item lines only, NO
        // ledger side, NO tax, NO Trial-Balance effect. Orders show ordered/delivered/
        // pending; notes show the in/out movement.
        if (in_array($voucher->type, Voucher::INVENTORY_WORKFLOW_TYPES, true)) {
            $isOrder = in_array($voucher->type, Voucher::ORDER_TYPES, true);
            if ($isOrder) {
                $voucher->load(['orderLines.stockItem.unit', 'orderLines.godown']);
                $rows = $voucher->orderLines->map(fn ($ol) => [
                    'item' => $ol->stockItem?->name ?? '(deleted item)',
                    'unit' => $ol->stockItem?->unit?->symbol,
                    'godown' => $ol->godown?->name ?? '—',
                    'ordered' => (float) $ol->ordered_qty,
                    'delivered' => (float) $ol->delivered_qty,
                    'pending' => round($ol->pendingQty(), 4),
                    'rate' => (float) $ol->rate,
                    'amount' => (float) $ol->amount,
                ])->values();
            } else {
                $rows = $voucher->stockEntries->map(function ($s) {
                    $isOut = $s->direction === 'out';

                    return [
                        'item' => $s->stockItem?->name ?? '(deleted item)',
                        'unit' => $s->stockItem?->unit?->symbol,
                        'godown' => $s->godown?->name ?? '—',
                        'direction' => $s->direction,
                        'qty' => (float) $s->quantity,
                        // Show the SELLING figure on an OUT (Delivery/Rejection Out);
                        // the entered/provisional figure on an IN (Receipt/Rejection In).
                        'rate' => $isOut ? (float) $s->sale_rate : (float) $s->rate,
                        'amount' => $isOut ? (float) $s->sale_value : (float) $s->value,
                    ];
                })->values();
            }

            return view('vouchers.print-workflow', [
                'voucher' => $voucher,
                'company' => $company,
                'documentTitle' => Voucher::TYPES[$voucher->type]['label'] ?? ucfirst($voucher->type),
                'isOrder' => $isOrder,
                'party' => $voucher->partyLedger,
                'subLabel' => $isOrder ? 'Commitment only — no stock, no accounting' : 'Stock movement — no accounting',
                'rows' => $rows,
            ]);
        }

        $isInvoice = $voucher->isInvoice();

        // Item invoice: show the stock lines. Only the SELLING side is printed — on a
        // sale that is sale_rate/sale_value; the weighted-average COST (rate/value)
        // never appears on the document. On a purchase the billed rate IS the cost,
        // so rate/value are the legitimate invoice figures.
        $items = $voucher->stockEntries->map(function ($s) {
            $isOut = $s->direction === 'out';
            $rate = $isOut ? (float) $s->sale_rate : (float) $s->rate;
            $amount = $isOut ? (float) $s->sale_value : (float) $s->value;

            return [
                'name' => $s->stockItem?->name ?? '(deleted item)',
                'hsn_sac' => $s->stockItem?->hsn_sac,
                'gst_rate' => $s->stockItem?->gst_rate !== null ? (float) $s->stockItem->gst_rate : null,
                'qty' => (float) $s->quantity,
                'unit' => $s->stockItem?->unit?->symbol,
                'rate' => $rate,
                'amount' => $amount,
            ];
        })->values();
        $party = $voucher->partyLedger;
        $company = $company ?? Shell::config();

        // Split an invoice's non-party legs into taxable (nominal) lines and GST
        // tax lines (ledgers that carry a tax_type). A plain voucher shows all legs.
        $nonParty = $voucher->entries->when($isInvoice, fn ($rows) => $rows->where('ledger_id', '!=', $voucher->party_ledger_id));

        $taxRows = $nonParty->filter(fn ($e) => $e->ledger && $e->ledger->tax_type !== null);
        $taxableRows = $nonParty->reject(fn ($e) => $e->ledger && $e->ledger->tax_type !== null);

        $allocation = $taxableRows->map(fn ($e) => [
            'ledger' => $e->ledger?->name ?? '(deleted ledger)',
            'dr_cr' => $e->dr_cr,
            'amount' => (float) $e->amount,
            'hsn_sac' => $e->ledger?->hsn_sac,
            'gst_rate' => $e->ledger?->gst_rate !== null ? (float) $e->ledger->gst_rate : null,
        ])->values();

        $taxBreakup = $taxRows->map(fn ($e) => [
            'ledger' => $e->ledger?->name ?? '(tax)',
            'tax_type' => $e->ledger?->tax_type,
            'amount' => (float) $e->amount,
        ])->values();

        $taxableTotal = (float) $taxableRows->sum('amount');
        $taxTotal = (float) $taxRows->sum('amount');

        // Invoice total is tax-inclusive (= the party leg); a plain voucher uses its Dr total.
        $total = $isInvoice ? ($taxableTotal + $taxTotal) : (float) $voucher->totalDr();

        // Determine the tax regime from the voucher's own tax ledgers (so historical
        // invoices print correctly regardless of the current company setting): a
        // 'vat' duty ledger → VAT (Nepal); central/state/integrated → GST (India).
        $isVat = $taxRows->contains(fn ($e) => $e->ledger && $e->ledger->tax_type === 'vat');
        $isGst = $taxRows->contains(fn ($e) => $e->ledger && in_array($e->ledger->tax_type, ['central', 'state', 'integrated'], true));
        $regime = $isVat ? 'vat' : ($isGst ? 'gst' : ((($company['vat']['enabled'] ?? false)) ? 'vat' : 'gst'));

        // Phase 8A — a Note prints as "Debit Note" / "Credit Note" (never "Tax
        // Invoice"), with the original invoice it adjusts shown below.
        $documentTitle = match ($voucher->type) {
            'credit_note' => 'Credit Note',
            'debit_note' => 'Debit Note',
            'sales' => $regime === 'vat' ? 'Tax Invoice' : 'Sales Invoice',
            'purchase' => $regime === 'vat' ? 'Tax Invoice' : 'Purchase Bill',
            default => (Voucher::TYPES[$voucher->type]['label'] ?? ucfirst($voucher->type)).' Voucher',
        };

        $currencyWord = $regime === 'vat' ? 'Nepali Rupees' : 'Indian Rupees';
        $taxIdLabel = $regime === 'vat' ? 'PAN' : 'GSTIN';
        $companyTaxId = $regime === 'vat'
            ? ($company['vat']['company_pan'] ?? null)
            : ($company['gst']['company_gstin'] ?? null);

        return view('vouchers.print', [
            'voucher' => $voucher,
            'company' => $company,
            'regime' => $regime,
            'taxIdLabel' => $taxIdLabel,
            'companyTaxId' => $companyTaxId,
            'companyGstin' => $companyTaxId, // kept for the existing header binding
            'isInvoice' => $isInvoice,
            'party' => $party,
            'items' => $items,
            'allocation' => $allocation,
            'taxBreakup' => $taxBreakup,
            'taxableTotal' => $taxableTotal,
            'taxTotal' => $taxTotal,
            'hasGst' => $taxBreakup->isNotEmpty(),
            'total' => $total,
            'totalWords' => Money::inWordsIndian($total, $currencyWord),
            'totalFormatted' => Money::indianFormat($total),
            'taxableFormatted' => Money::indianFormat($taxableTotal),
            'documentTitle' => $documentTitle,
            'referenceOriginal' => $voucher->referenceVoucher?->displayNumber(), // Phase 8A — the adjusted invoice
            'allEntries' => $voucher->entries->map(fn ($e) => [
                'ledger' => $e->ledger?->name ?? '(deleted ledger)',
                'dr_cr' => $e->dr_cr,
                'amount' => (float) $e->amount,
            ])->values(),
        ]);
    }

    public function dayBook()
    {
        return view('vouchers.day-book', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'region' => 'Day Book',
        ]);
    }
}
