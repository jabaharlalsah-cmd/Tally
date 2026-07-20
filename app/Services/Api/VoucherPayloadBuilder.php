<?php

namespace App\Services\Api;

use App\Models\CompanyFeature;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Services\GstService;
use App\Services\VatService;
use App\Support\ApiMoney;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16B — translate an API voucher request into the INTERNAL payload VoucherScreen::post()
 * consumes. This is a TRANSLATOR, not a second posting path: it assembles the balanced `lines`
 * (party leg + taxable legs + server-computed tax/TDS legs) exactly the way the UI's own client
 * builds them, then hands them to the identical post(), which independently re-verifies every
 * number to the paise. It computes NOTHING the server does not also re-check.
 *
 * Tax is computed with the SAME GstService/VatService methods post()'s verify hooks recompute
 * with — so verification passes by construction, and an API-posted voucher is byte-identical to
 * the same voucher posted through the UI.
 *
 * The 8 exposed types map 1:1 to internal strings. Everything else (orders, stock, workflow) is
 * refused before assembly.
 */
class VoucherPayloadBuilder
{
    /** The only types the API exposes. Internal strings, passed through unchanged. */
    public const EXPOSED_TYPES = [
        'sales', 'purchase', 'receipt', 'payment', 'journal', 'contra', 'credit_note', 'debit_note',
    ];

    private const INVOICE_TYPES = ['sales', 'purchase', 'credit_note', 'debit_note'];

    public function __construct(
        private GstService $gst,
        private VatService $vat,
    ) {}

    /**
     * @return array  the internal payload for VoucherScreen::post()
     *
     * @throws ValidationException  API-shape errors (missing/invalid fields) → 422 envelope
     * @throws ApiVoucherException  a business refusal with a stable code (unsupported type, etc.)
     */
    public function build(array $api): array
    {
        $type = $api['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::EXPOSED_TYPES, true)) {
            throw new ApiVoucherException('unsupported_voucher_type', 422, ['type' => $type]);
        }

        $this->requireDate($api);

        return match (true) {
            in_array($type, self::INVOICE_TYPES, true) => $this->buildInvoice($type, $api),
            in_array($type, ['receipt', 'payment'], true) => $this->buildCashDoc($type, $api),
            default => $this->buildJournal($type, $api),   // journal | contra
        };
    }

    // ── journal / contra — explicit balanced lines, passed straight through ──────────────

    private function buildJournal(string $type, array $api): array
    {
        $lines = $api['lines'] ?? null;

        if (! is_array($lines) || count($lines) < 2) {
            $this->fail('lines', 'A '.$type.' needs at least two balanced lines.');
        }

        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = [
                'ledger_id' => $this->intField($line, 'ledger_id', "lines.$i.ledger_id"),
                'dr_cr' => $this->drCr($line, "lines.$i.dr_cr"),
                'amount' => $this->amountFloat($line['amount'] ?? null, "lines.$i.amount"),
                'allocations' => $this->allocations($line['bill_allocations'] ?? $line['allocations'] ?? null, "lines.$i"),
            ];
        }

        return $this->base($type, $api) + ['lines' => $out];
    }

    // ── receipt / payment — party + bank, optional against-ref and server-computed TDS ──

    private function buildCashDoc(string $type, array $api): array
    {
        $partyId = $this->intField($api, 'party_ledger_id', 'party_ledger_id');
        $bankId = $this->intField($api, 'bank_ledger_id', 'bank_ledger_id');
        $grossP = $this->amountPaise($api['amount'] ?? null, 'amount');

        // payment: money OUT → Dr party/expense, Cr bank.  receipt: money IN → Dr bank, Cr party.
        $partySide = $type === 'payment' ? 'Dr' : 'Cr';
        $bankSide = $type === 'payment' ? 'Cr' : 'Dr';

        $tds = $api['tds_deduction'] ?? null;
        $deductedP = 0;
        $payload = $this->base($type, $api);

        if ($tds !== null) {
            if ($type !== 'payment') {
                $this->fail('tds_deduction', 'TDS can only be deducted on a payment.');
            }
            [$deductedP, $tdsDeclaration] = $this->resolveTds($tds, $grossP, $api);
            $payload['tds_deduction'] = $tdsDeclaration;
        }

        $lines = [[
            'ledger_id' => $partyId,
            'dr_cr' => $partySide,
            'amount' => ApiMoney::forPost($grossP),
            'allocations' => $this->allocations($api['bill_allocations'] ?? null, 'bill_allocations'),
        ]];

        if ($deductedP > 0) {
            // Server-computed TDS Payable leg — the client never sends this amount.
            $lines[] = [
                'ledger_id' => $this->tdsPayableLedgerId(),
                'dr_cr' => 'Cr',
                'amount' => ApiMoney::forPost($deductedP),
            ];
        }

        // Bank leg carries the NET after TDS.
        $lines[] = [
            'ledger_id' => $bankId,
            'dr_cr' => $bankSide,
            'amount' => ApiMoney::forPost($grossP - $deductedP),
        ];

        return $payload + ['lines' => $lines];
    }

    // ── invoices — sales/purchase/credit_note/debit_note, server-computed tax ────────────

    private function buildInvoice(string $type, array $api): array
    {
        $partyId = $this->intField($api, 'party_ledger_id', 'party_ledger_id');
        $partySide = in_array($type, ['sales', 'debit_note'], true) ? 'Dr' : 'Cr';
        $ledgerSide = in_array($type, ['sales', 'debit_note'], true) ? 'Cr' : 'Dr';

        $items = $api['items'] ?? null;
        $lines = [];
        $baseP = 0;

        if (is_array($items) && $items !== []) {
            // Item mode: one aggregate revenue leg + the item detail; tax derived from item rates.
            $revenueLedgerId = $this->intField($api, 'revenue_ledger_id', 'revenue_ledger_id');
            [$itemRows, $taxable, $baseP] = $this->itemsTaxable($items);
            $lines[] = ['ledger_id' => $revenueLedgerId, 'dr_cr' => $ledgerSide, 'amount' => ApiMoney::forPost($baseP)];
            $api['_items'] = $itemRows;
        } else {
            // Ledger mode: explicit taxable revenue legs.
            $rawLines = $api['lines'] ?? null;
            if (! is_array($rawLines) || $rawLines === []) {
                $this->fail('lines', 'An invoice needs at least one revenue line, or an items array.');
            }
            $taxable = [];
            foreach ($rawLines as $i => $line) {
                $lid = $this->intField($line, 'ledger_id', "lines.$i.ledger_id");
                $amtP = $this->amountPaise($line['amount'] ?? null, "lines.$i.amount");
                $baseP += $amtP;
                $lines[] = ['ledger_id' => $lid, 'dr_cr' => $ledgerSide, 'amount' => ApiMoney::forPost($amtP)];
                $taxable[] = ['ledger_id' => $lid, 'amount' => ApiMoney::forPost($amtP)];
            }
        }

        // Server-authoritative tax, via the SAME method post()'s verify recomputes with.
        $taxP = 0;
        foreach ($this->computeTax($type, $partyId, $taxable) as $taxLine) {
            $lines[] = ['ledger_id' => $taxLine['ledger_id'], 'dr_cr' => $taxLine['dr_cr'], 'amount' => $taxLine['amount']];
            $taxP += (int) round(((float) $taxLine['amount']) * 100);
        }

        $totalP = $baseP + $taxP;

        // Optional client sanity check — reject a disagreeing expected_total BEFORE posting.
        if (array_key_exists('expected_total', $api) && $api['expected_total'] !== null) {
            $expectedP = $this->amountPaise($api['expected_total'], 'expected_total');
            if ($expectedP !== $totalP) {
                throw new ApiVoucherException('total_mismatch', 422, [
                    'expected_total' => ApiMoney::fromPaise($expectedP),
                    'computed_total' => ApiMoney::fromPaise($totalP),
                ]);
            }
        }

        // Party leg = base + tax, carrying any bill-wise allocations.
        array_unshift($lines, [
            'ledger_id' => $partyId,
            'dr_cr' => $partySide,
            'amount' => ApiMoney::forPost($totalP),
            'allocations' => $this->allocations($api['bill_allocations'] ?? null, 'bill_allocations'),
        ]);

        $payload = $this->base($type, $api) + [
            'party_ledger_id' => $partyId,
            'reference_no' => isset($api['reference_no']) ? (string) $api['reference_no'] : null,
            'reference_date' => $api['reference_date'] ?? null,
            'reference_voucher_id' => isset($api['reference_voucher_id']) ? (int) $api['reference_voucher_id'] : null,
            'lines' => $lines,
        ];

        if (isset($api['_items'])) {
            $payload['items'] = $api['_items'];
        }

        return $payload;
    }

    // ── tax + tds helpers ───────────────────────────────────────────────────────────────

    /** Compute duty lines with whichever regime the company runs (GST, VAT, or neither). */
    private function computeTax(string $type, int $partyId, array $taxable): array
    {
        $features = CompanyFeature::current();

        if ($features->gst) {
            $partyState = \App\Models\Ledger::whereKey($partyId)->value('state');

            return $this->gst->computeInvoiceTax($type, $partyState, $taxable)['lines'];
        }

        if ($features->vat) {
            return $this->vat->computeInvoiceTax($type, $taxable)['lines'];
        }

        return [];   // as-voucher (no tax regime) — post()'s verify no-ops
    }

    /**
     * Replicates GstService::taxableFromItems (private): one taxable entry per item with the
     * item's server-side gst_rate. Also returns the normalized item rows and the total base paise.
     */
    private function itemsTaxable(array $items): array
    {
        $ids = array_map(fn ($it) => (int) ($it['stock_item_id'] ?? 0), $items);
        $rates = StockItem::whereIn('id', $ids)->pluck('gst_rate', 'id')->all();

        $rows = [];
        $taxable = [];
        $baseP = 0;

        foreach ($items as $i => $it) {
            $iid = (int) ($it['stock_item_id'] ?? 0);
            if ($iid === 0) {
                $this->fail("items.$i.stock_item_id", 'A stock item id is required.');
            }
            $qty = (float) ($it['qty'] ?? 0);
            $rate = (float) ($it['rate'] ?? 0);
            $lineBaseP = (int) round($qty * $rate * 100);
            $baseP += $lineBaseP;

            $rows[] = [
                'stock_item_id' => $iid,
                'godown_id' => isset($it['godown_id']) ? (int) $it['godown_id'] : null,
                'qty' => $qty,
                'rate' => $rate,
            ];
            $taxable[] = ['amount' => round($qty * $rate, 2), 'rate' => isset($rates[$iid]) ? (float) $rates[$iid] : 0.0];
        }

        return [$rows, $taxable, $baseP];
    }

    /**
     * Compute the TDS deducted amount server-side and return [deductedPaise, declaration]. The
     * client provides only WHO/WHICH-SECTION/BASE; the amount is ours, and post() re-verifies it.
     */
    private function resolveTds(array $tds, int $grossP, array $api): array
    {
        $deducteeId = $this->intField($tds, 'deductee_ledger_id', 'tds_deduction.deductee_ledger_id');
        $sectionId = $this->intField($tds, 'tds_section_id', 'tds_deduction.tds_section_id');
        $baseP = array_key_exists('base_amount', $tds)
            ? $this->amountPaise($tds['base_amount'], 'tds_deduction.base_amount')
            : $grossP;

        $fyStart = Voucher::fyStartFor(\Illuminate\Support\Carbon::parse($api['date']));

        [$deductedP] = app(\App\Services\TdsService::class)->computeDeduction($deducteeId, $sectionId, $baseP, $fyStart);

        return [$deductedP, [
            'deductee_ledger_id' => $deducteeId,
            'tds_section_id' => $sectionId,
            'base_amount' => ApiMoney::forPost($baseP),
        ]];
    }

    private function tdsPayableLedgerId(): int
    {
        // The canonical duty-ledger resolver — the same one TdsService::persist/verify use.
        $id = app(\App\Services\TdsService::class)->payableLedgerId();

        if (! $id) {
            throw new ApiVoucherException('conflict', 409, ['reason' => 'This company has no TDS Payable ledger; enable TDS in company features first.']);
        }

        return (int) $id;
    }

    // ── small field helpers ─────────────────────────────────────────────────────────────

    private function base(string $type, array $api): array
    {
        return [
            'type' => $type,
            'date' => $api['date'],
            'narration' => isset($api['narration']) ? (string) $api['narration'] : null,
        ];
    }

    private function requireDate(array $api): void
    {
        if (empty($api['date']) || ! is_string($api['date']) || strtotime($api['date']) === false) {
            $this->fail('date', 'A valid date is required.');
        }
    }

    private function allocations($raw, string $path): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            $this->fail($path.'.bill_allocations', 'Bill allocations must be an array.');
        }

        $out = [];
        foreach ($raw as $i => $a) {
            $out[] = [
                'ref_type' => (string) ($a['ref_type'] ?? ''),
                'ref_name' => (string) ($a['ref_name'] ?? ''),
                'amount' => $this->amountFloat($a['amount'] ?? null, "$path.bill_allocations.$i.amount"),
                'due_date' => $a['due_date'] ?? null,
            ];
        }

        return $out;
    }

    private function intField(array $src, string $key, string $path): int
    {
        $v = $src[$key] ?? null;
        if ($v === null || (! is_int($v) && ! (is_string($v) && ctype_digit($v)))) {
            $this->fail($path, 'A valid '.$key.' is required.');
        }

        return (int) $v;
    }

    private function drCr(array $line, string $path): string
    {
        $v = $line['dr_cr'] ?? null;
        if (! in_array($v, ['Dr', 'Cr'], true)) {
            $this->fail($path, "dr_cr must be 'Dr' or 'Cr'.");
        }

        return $v;
    }

    /** Parse money to paise exactly; a bad value is a 422 field error. */
    private function amountPaise($value, string $path): int
    {
        if ($value === null || ! ApiMoney::isValid($value)) {
            $this->fail($path, 'A valid money amount is required (decimal string, e.g. "1500.00").');
        }

        return ApiMoney::toPaise($value);
    }

    /** The float form post() consumes, derived from an exact paise parse (never a raw float). */
    private function amountFloat($value, string $path): float
    {
        return ApiMoney::forPost($this->amountPaise($value, $path));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
