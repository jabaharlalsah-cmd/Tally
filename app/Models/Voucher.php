<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\RecordsSyncChanges;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    /** Phase 12C-1 — transient: item ids captured at deleting, refolded at deleted. */
    public array $interCompanyLotItemIds = [];

    use BelongsToCompany;
    use RecordsSyncChanges; // Phase 7C — appends to the sync_changes pull change-log

    protected $fillable = [
        'type', 'number', 'fy_start', 'date', 'narration',
        'party_ledger_id', 'reference_no', 'reference_date',
        'client_uuid', // Phase 7C — desktop idempotency key
        'reference_voucher_id', // Phase 8A — the original invoice a Note adjusts
        'scenario_id', // Phase 15C — provisional tag (null = real voucher)
        'promoted_from_scenario_id', // Phase 15C — audit trail for a now-real voucher
    ];

    protected $casts = [
        'date' => 'date',
        'reference_date' => 'date',
        'fy_start' => 'integer',
        'number' => 'integer',
        'party_ledger_id' => 'integer',
        'reference_voucher_id' => 'integer',
        'scenario_id' => 'integer',
        'promoted_from_scenario_id' => 'integer',
    ];

    /** Voucher type metadata — label, function key, and the bank-side default. */
    public const TYPES = [
        'contra' => ['label' => 'Contra', 'key' => 'F4', 'abbr' => 'Ctra'],
        'payment' => ['label' => 'Payment', 'key' => 'F5', 'abbr' => 'Pymt'],
        'receipt' => ['label' => 'Receipt', 'key' => 'F6', 'abbr' => 'Rcpt'],
        'journal' => ['label' => 'Journal', 'key' => 'F7', 'abbr' => 'Jrnl'],
        'sales' => ['label' => 'Sales', 'key' => 'F8', 'abbr' => 'Sale'],
        'purchase' => ['label' => 'Purchase', 'key' => 'F9', 'abbr' => 'Purc'],
        // Phase 8A — Debit / Credit Notes (returns & post-invoice adjustments). Party-
        // centric like invoices; reached via Ctrl+F8 / Ctrl+F9 (Tally's stable bindings).
        'credit_note' => ['label' => 'Credit Note', 'key' => 'Ctrl+F8', 'abbr' => 'CrNt'],
        'debit_note' => ['label' => 'Debit Note', 'key' => 'Ctrl+F9', 'abbr' => 'DrNt'],
        // Phase 8B — inventory-workflow vouchers: ZERO accounting impact (no ledger
        // entries), commitment/stock tracking only. Orders move no stock; Delivery/
        // Receipt Notes and Rejections move real stock. Reached via Alt+F* / Ctrl+F*.
        'sales_order' => ['label' => 'Sales Order', 'key' => 'Alt+F6', 'abbr' => 'SO'],
        'purchase_order' => ['label' => 'Purchase Order', 'key' => 'Alt+F7', 'abbr' => 'PO'],
        'delivery_note' => ['label' => 'Delivery Note', 'key' => 'Alt+F8', 'abbr' => 'DelN'],
        'receipt_note' => ['label' => 'Receipt Note', 'key' => 'Alt+F5', 'abbr' => 'RcpN'],
        'rejection_out' => ['label' => 'Rejections Out', 'key' => 'Ctrl+F6', 'abbr' => 'RejO'],
        'rejection_in' => ['label' => 'Rejections In', 'key' => 'Ctrl+F5', 'abbr' => 'RejI'],
        // Phase 6C — pure stock-movement vouchers (no ledger side, no function key;
        // reached via Go To / the Gateway's Inventory Vouchers section).
        'stock_journal' => ['label' => 'Stock Journal', 'key' => null, 'abbr' => 'StkJ'],
        'physical_stock' => ['label' => 'Physical Stock', 'key' => null, 'abbr' => 'Phys'],
    ];

    /** Voucher types that move stock only — no `voucher_entries`, no Dr/Cr balance. */
    public const STOCK_TYPES = ['stock_journal', 'physical_stock'];

    /** Types that support Tally's party-centric "as Invoice" entry mode. */
    public const INVOICE_TYPES = ['sales', 'purchase', 'credit_note', 'debit_note'];

    /** Phase 8A — Debit & Credit Notes (mirror-opposites of Purchase & Sales). */
    public const NOTE_TYPES = ['credit_note', 'debit_note'];

    /** Phase 8B — pure commitments: item lines only, no stock, no accounting. */
    public const ORDER_TYPES = ['sales_order', 'purchase_order'];

    /** Phase 8B — inventory-workflow vouchers that MOVE stock but post no accounting. */
    public const STOCK_WORKFLOW_TYPES = ['delivery_note', 'receipt_note', 'rejection_out', 'rejection_in'];

    /** Phase 8B — every item-only, zero-accounting inventory-workflow voucher. */
    public const INVENTORY_WORKFLOW_TYPES = ['sales_order', 'purchase_order', 'delivery_note', 'receipt_note', 'rejection_out', 'rejection_in'];

    /**
     * Stock direction for an item line of a given voucher type. Goods LEAVE on a
     * Sales / Debit Note (purchase return) / Delivery Note / Rejections Out; goods
     * COME IN on a Purchase / Credit Note (sales return) / Receipt Note / Rejections
     * In. Getting this backwards is the classic mistake — hence one explicit rule.
     */
    public static function stockDirection(string $type): string
    {
        return in_array($type, ['sales', 'debit_note', 'delivery_note', 'rejection_out'], true) ? 'out' : 'in';
    }

    /** The stock_entries.movement_type tag for an item line of this voucher type. */
    public static function movementTypeFor(string $type): string
    {
        return match ($type) {
            'purchase', 'receipt_note' => 'purchase',
            'credit_note', 'rejection_in' => 'sales_return',
            'debit_note', 'rejection_out' => 'purchase_return',
            'delivery_note' => 'sale',
            default => 'sale', // sales
        };
    }

    /** Phase 8B — the order-line commitments of a Sales/Purchase Order. */
    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_no');
    }

    protected static function booted(): void
    {
        // Phase 8B — cancelling a Delivery/Receipt Note or a Rejection that fulfilled
        // an order must roll back the order's delivered_qty. This runs BEFORE the FK
        // cascade removes the fulfillment rows, so OrderService deletes them explicitly
        // and recomputes delivered_qty from what remains.
        static::deleting(function (Voucher $voucher) {
            if (in_array($voucher->type, self::STOCK_WORKFLOW_TYPES, true)) {
                app(\App\Services\OrderService::class)->reverseFulfillment($voucher);
            }

            // Phase 10A — cancelling a TDS-deducting Payment must roll the deductee's
            // year-to-date threshold state back, or the next payment would be measured
            // against an aggregate that includes a voucher that no longer exists. Runs
            // before the FK cascade removes the tds_deductions rows, while they are
            // still readable. A guard keeps this inert on a tenant that has not yet
            // migrated the TDS tables (mirrors the RecordsSyncChanges discipline).
            if ($voucher->type === 'payment' && \Illuminate\Support\Facades\Schema::hasTable('tds_deductions')) {
                app(\App\Services\TdsService::class)->reverseFor($voucher);
            }

            // Phase 12C-1 → 13 — remember the items whose lot state this cancel will disturb (its own
            // lots AND depletions it caused vanish or must re-apply elsewhere), so the deleted hook can
            // refold them after the FK cascade. The table is `stock_lots` since Phase 13 (was
            // `inter_company_stock_lots`); the stale name here silently disabled every cancel-path
            // refold — for FIFO/LIFO remaining_qty + OUT costs AND 12C-1 inter-company provenance.
            if (\Illuminate\Support\Facades\Schema::hasTable('stock_lots')) {
                $voucher->interCompanyLotItemIds = $voucher->stockEntries()->pluck('stock_item_id')->all();
            }
        });

        // Phase 12C-1 — the exact repair after a cancel: the FK cascade has removed
        // this voucher's stock rows (and its lots, via stock_entry_id); replaying
        // the affected items re-derives every other voucher's depletion trace.
        static::deleted(function (Voucher $voucher) {
            $itemIds = $voucher->interCompanyLotItemIds ?? [];
            if ($itemIds !== []) {
                app(\App\Services\StockLotService::class)->refoldItems($itemIds);
            }
        });
    }

    /** The original invoice this Note adjusts (Sales for a Credit Note, Purchase for a Debit Note). */
    public function referenceVoucher(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_voucher_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class)->orderBy('line_no');
    }

    public function billAllocations(): HasMany
    {
        return $this->hasMany(BillAllocation::class);
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(CostAllocation::class);
    }

    public function stockEntries(): HasMany
    {
        return $this->hasMany(StockEntry::class)->orderBy('line_no');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function partyLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'party_ledger_id');
    }

    /** True for a Sales/Purchase voucher captured in invoice mode (party set). */
    public function isInvoice(): bool
    {
        return in_array($this->type, self::INVOICE_TYPES, true) && $this->party_ledger_id !== null;
    }

    /**
     * BOOKS fiscal-year starting year for a date — Phase 12A: honours the active
     * company's financial_year_start_month (default 4 = April, the pre-12A rule).
     * A calendar-year company (month 1) buckets 2026-02-10 into fy_start 2026.
     * Drives vouchers.fy_start at post, per-FY numbering, and report defaults.
     */
    public static function fyStartFor(Carbon $date): int
    {
        $m = \App\Support\ActiveCompany::company()?->financial_year_start_month ?? 4;

        return $date->month >= $m ? $date->year : $date->year - 1;
    }

    /**
     * STATUTORY Indian FY (Apr–Mar), regardless of the company's books FY.
     * The TDS engine keys on this — thresholds, section effectivity, YTD
     * aggregation and the 26Q assessment-year math are Apr–Mar by statute even
     * for a company keeping calendar-year books.
     */
    public static function statutoryFyStartFor(Carbon $date): int
    {
        return $date->month >= 4 ? $date->year : $date->year - 1;
    }

    /** The company FY's opening date for a books fy_start year (Phase 12A). */
    public static function fyOpenFor(int $fyStart): Carbon
    {
        $m = \App\Support\ActiveCompany::company()?->financial_year_start_month ?? 4;

        return Carbon::create($fyStart, $m, 1);
    }

    /** Books FY label — "2026-27" for a spanning FY, plain "2026" for calendar-year books. */
    public static function fyLabel(int $fyStart): string
    {
        $m = \App\Support\ActiveCompany::company()?->financial_year_start_month ?? 4;

        return $m === 1 ? (string) $fyStart : $fyStart.'-'.substr((string) ($fyStart + 1), -2);
    }

    /** Statutory Apr–Mar FY label — always spanning ("2026-27"); TDS display uses this. */
    public static function statutoryFyLabel(int $fyStart): string
    {
        return $fyStart.'-'.substr((string) ($fyStart + 1), -2);
    }

    /** Next sequential number for a type within a financial year. */
    public static function nextNumber(string $type, int $fyStart): int
    {
        return (int) self::where('type', $type)->where('fy_start', $fyStart)->max('number') + 1;
    }

    public function displayNumber(): string
    {
        return strtoupper(self::TYPES[$this->type]['abbr'] ?? $this->type).'-'.$this->number;
    }

    public function totalDr(): float
    {
        return (float) $this->entries->where('dr_cr', 'Dr')->sum('amount');
    }

    public function totalCr(): float
    {
        return (float) $this->entries->where('dr_cr', 'Cr')->sum('amount');
    }

    /** Row shape for the Day Book list. */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type]['label'] ?? ucfirst($this->type),
            'number' => $this->number,
            'display_number' => $this->displayNumber(),
            'date' => $this->date->toDateString(),
            'date_label' => $this->date->format('d-M-Y'),
            'narration' => $this->narration,
            'amount' => $this->totalDr(),
            'is_invoice' => $this->isInvoice(),
            'is_stock' => in_array($this->type, self::STOCK_TYPES, true),
            'stock_summary' => $this->stockSummary(),
            'party_ledger_id' => $this->party_ledger_id,
            'reference_no' => $this->reference_no,
            'reference_date' => $this->reference_date?->toDateString(),
            'lines' => $this->entries->map(fn ($e) => [
                'ledger' => $e->ledger?->name,
                'dr_cr' => $e->dr_cr,
                'amount' => (float) $e->amount,
            ])->all(),
        ];
    }

    /**
     * One-line description of a stock voucher's movement, for the Day Book (these
     * have no ledger lines). Empty for accounting vouchers. Reads the loaded
     * stockEntries relation — no extra query when it is eager-loaded.
     */
    public function stockSummary(): string
    {
        if (! in_array($this->type, self::STOCK_TYPES, true)) {
            return '';
        }
        $rows = $this->stockEntries;
        if ($rows->isEmpty()) {
            return $this->type === 'physical_stock' ? 'Stock-take — no variance' : '—';
        }
        $first = $rows->first();
        $item = $first->stockItem?->name ?? 'item';
        $qty = rtrim(rtrim(number_format((float) $first->quantity, 4, '.', ''), '0'), '.');
        $unit = $first->stockItem?->unit?->symbol;
        $q = $qty.($unit ? ' '.$unit : '');

        if ($this->type === 'stock_journal') {
            if ($rows->count() >= 2) {
                $out = $rows->firstWhere('direction', 'out');
                $in = $rows->firstWhere('direction', 'in');

                return sprintf('%s · %s · %s → %s', $item, $q, $out?->godown?->name ?? '—', $in?->godown?->name ?? '—');
            }

            return sprintf('%s · %s consumed · %s', $item, $q, $first->godown?->name ?? '—');
        }

        // physical_stock: one variance row (in = excess, out = shortage).
        $sign = $first->direction === 'in' ? '+' : '−';

        return sprintf('%s · %s%s (stock-take) · %s', $item, $sign, $q, $first->godown?->name ?? '—');
    }
}
