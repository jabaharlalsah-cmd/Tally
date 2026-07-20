<?php

namespace App\Services\TallyImport;

use App\Livewire\VoucherScreen;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Posts parsed Tally vouchers into ZeroBook through the ONE shared, fully-validated
 * path — {@see VoucherScreen::post()} — under {@see BulkMode} (Phase 7A).
 *
 * ── Chronological order is a correctness invariant, not a nicety ──────────────
 * Each sale's cost is locked at the running weighted average AT THE MOMENT it is
 * posted (from the movements already in the table). So the vouchers MUST be posted
 * in strict (voucher_date, then Tally's within-day file sequence) order — a sale
 * posted before an earlier-dated purchase would lock a cost that is missing that
 * purchase. This importer sorts explicitly (never trusting the export's order) and
 * then asserts the posted sequence is non-decreasing in date.
 *
 * ── All-or-nothing ────────────────────────────────────────────────────────────
 * Every voucher still runs the balance gate, GST/VAT authority, bill-wise and
 * cost-centre checks. A voucher that violates any of them is recorded as a
 * REJECTION (with the specific voucher named) and the whole import is rolled back
 * by the orchestrator — a half-imported book is never left behind.
 */
class VoucherImporter
{
    /** Tally voucher-type name (lower-cased) → ZeroBook type. */
    private const TYPE_MAP = [
        'payment' => 'payment',
        'receipt' => 'receipt',
        'contra' => 'contra',
        'journal' => 'journal',
        'sales' => 'sales',
        'purchase' => 'purchase',
        'debit note' => 'journal',   // purchase return / adjustment → Journal accounting
        'credit note' => 'journal',  // sales return / adjustment    → Journal accounting
        'stock journal' => 'stock_journal',
        'physical stock' => 'physical_stock',
    ];

    private VoucherScreen $screen;

    /** @var array<string,int> "type|fy" => last number assigned */
    private array $cursor = [];
    /** @var array<string,int> "type|fy" => existing MAX at import start */
    private array $seed = [];
    /** @var array<string,array<int,bool>> "type|fy" => set of numbers used */
    private array $used = [];

    public int $posted = 0;
    /** @var array<string,int> */
    public array $byType = [];
    /** @var array<int,array{voucher:string,date:string,reason:string}> */
    public array $rejections = [];
    /** @var array<int,array{voucher:string,reason:string}> */
    public array $skipped = [];
    /** @var array<int,string> the actual chronological post sequence (labels) */
    public array $postOrder = [];
    public bool $reordered = false;

    public function __construct(?VoucherScreen $screen = null)
    {
        $this->screen = $screen ?? new VoucherScreen();
    }

    /**
     * Sort voucher records into strict chronological order — voucher date ascending,
     * then a within-day sequence tie-breaker. THIS is the ordering weighted-average
     * COGS depends on. Shared verbatim by the Tally importer (Phase 7A) and the
     * desktop sync push (Phase 7C), so there is exactly one chronological-ordering
     * rule and neither path can drift from it.
     */
    public static function chronologicalSort(array $records, string $seqKey = 'file_index'): array
    {
        usort($records, fn ($a, $b) => [$a['date'], $a[$seqKey] ?? 0] <=> [$b['date'], $b[$seqKey] ?? 0]);

        return $records;
    }

    public function import(array $vouchers, Resolver $maps): void
    {
        // Stable sort: date ascending, then the original file position (Tally's own
        // within-day sequence) as the explicit tie-breaker.
        $sorted = self::chronologicalSort($vouchers, 'file_index');
        $this->reordered = array_map(fn ($v) => $v['file_index'], $sorted) !== range(0, count($sorted) - 1);

        $prevDate = null;
        foreach ($sorted as $v) {
            // Guard the invariant explicitly: the sort must have produced a
            // non-decreasing date sequence before we post cost-sensitive movements.
            if ($prevDate !== null && $v['date'] < $prevDate) {
                throw new \RuntimeException("Chronological ordering violated at {$v['date']} (after {$prevDate}).");
            }
            $prevDate = $v['date'];

            $this->importOne($v, $maps);
        }
    }

    private function importOne(array $v, Resolver $maps): void
    {
        $label = $this->label($v);

        $type = self::TYPE_MAP[strtolower(trim($v['type_raw']))] ?? null;
        if ($type === null) {
            $this->reject($label, $v['date'], "Unsupported Tally voucher type “{$v['type_raw']}”.");

            return;
        }

        // Standalone inventory vouchers (Stock Journal / Physical Stock) are outside
        // 7A's scope — skipped and REPORTED (never silently dropped) so the customer
        // re-keys the handful that exist. They carry no ledger side, so skipping them
        // cannot unbalance the Trial Balance.
        if (in_array($type, Voucher::STOCK_TYPES, true)) {
            $this->skipped[] = ['voucher' => $label, 'reason' => 'Stock-movement voucher (re-key in ZeroBook after migration).'];

            return;
        }

        try {
            $payload = $this->buildPayload($v, $type, $maps);
        } catch (ResolveException $e) {
            $this->reject($label, $v['date'], $e->getMessage());

            return;
        }

        try {
            // Phase 12B — the importer is a machine replaying history: it cannot
            // "declare" an inter-company tag the way an interactive user does, so it
            // self-declares exactly what the server derives (persist re-derives
            // anyway — nothing is trusted). Without this, importing a grouped book
            // whose vouchers touch a linked party would hard-fail on every one.
            $derived = app(\App\Services\InterCompanyService::class)->deriveCounterparties($payload['lines'] ?? []);
            if (count($derived) === 1) {
                $payload['intercompany'] = ['counterparty_company_id' => $derived[0]];
            }

            $this->screen->post($payload);
            $this->posted++;
            $this->byType[$type] = ($this->byType[$type] ?? 0) + 1;
            $this->postOrder[] = $label;
        } catch (ValidationException $e) {
            $this->reject($label, $v['date'], $this->flatten($e));
        } catch (Throwable $e) {
            $this->reject($label, $v['date'], $e->getMessage());
        }
    }

    /** Build the exact VoucherScreen::post() payload for one voucher. */
    private function buildPayload(array $v, string $type, Resolver $maps): array
    {
        $isInvoice = in_array($type, Voucher::INVOICE_TYPES, true);

        $fy = Voucher::fyStartFor(Carbon::parse($v['date']));

        $payload = [
            'type' => $type,
            'date' => $v['date'],
            'number' => $this->assignNumber($type, $fy, $v['number_raw']),
            'narration' => $v['narration'],
        ];

        if ($isInvoice) {
            $payload['reference_no'] = $v['reference_no'];
            $payload['reference_date'] = $v['reference_date'];
            if (! empty($v['party'])) {
                $payload['party_ledger_id'] = $this->ledgerId($maps, $v['party']);
            }
        }

        $payload['lines'] = [];
        foreach ($v['lines'] as $ln) {
            $line = [
                'ledger_id' => $this->ledgerId($maps, $ln['ledger']),
                'dr_cr' => $ln['dr_cr'],
                'amount' => $ln['amount'],
            ];
            if (! empty($ln['bill_allocations'])) {
                $line['allocations'] = array_map(fn ($b) => [
                    'ref_type' => $b['ref_type'],
                    'ref_name' => $b['ref_name'],
                    'amount' => $b['amount'],
                    'due_date' => $b['due_date'],
                ], $ln['bill_allocations']);
            }
            if (! empty($ln['cost_allocations'])) {
                $line['cost_allocations'] = array_map(fn ($c) => [
                    'cost_centre_id' => $this->costCentreId($maps, $c['cost_centre']),
                    'amount' => $c['amount'],
                ], $ln['cost_allocations']);
            }
            $payload['lines'][] = $line;
        }

        if ($isInvoice && ! empty($v['items'])) {
            $payload['items'] = array_map(fn ($it) => [
                'stock_item_id' => $this->stockItemId($maps, $it['stock_item']),
                'godown_id' => ! empty($it['godown']) ? ($maps->godowns[$it['godown']] ?? null) : null,
                'qty' => $it['qty'],
                'rate' => $it['rate'],
            ], $v['items']);
        }

        return $payload;
    }

    /**
     * Assign the ZeroBook voucher number without a per-voucher SELECT MAX. Prefers
     * Tally's own number when it is a clean positive integer that is still free in
     * this (type, financial-year) bucket AND beyond any pre-existing numbering (so it
     * can never collide with a voucher already in the DB); otherwise the next value
     * from an in-memory cursor. Both are O(1) and collision-free.
     */
    private function assignNumber(string $type, int $fy, string $tallyRaw): int
    {
        $key = $type.'|'.$fy;
        if (! isset($this->cursor[$key])) {
            $this->seed[$key] = Voucher::nextNumber($type, $fy) - 1; // current MAX (0 for a fresh book)
            $this->cursor[$key] = $this->seed[$key];
            $this->used[$key] = [];
        }

        $tally = preg_match('/^\d+$/', trim($tallyRaw)) ? (int) $tallyRaw : 0;
        if ($tally > $this->seed[$key] && ! isset($this->used[$key][$tally])) {
            $n = $tally;
        } else {
            $n = $this->cursor[$key];
            do {
                $n++;
            } while (isset($this->used[$key][$n]));
        }

        $this->used[$key][$n] = true;
        $this->cursor[$key] = max($this->cursor[$key], $n);

        return $n;
    }

    private function ledgerId(Resolver $maps, string $name): int
    {
        $id = $maps->ledgers[trim($name)] ?? null;
        if ($id === null) {
            throw new ResolveException("Unknown ledger “{$name}” (not present in the masters export).");
        }

        return $id;
    }

    private function stockItemId(Resolver $maps, string $name): int
    {
        $id = $maps->stockItems[trim($name)] ?? null;
        if ($id === null) {
            throw new ResolveException("Unknown stock item “{$name}” (not present in the masters export).");
        }

        return $id;
    }

    private function costCentreId(Resolver $maps, string $name): int
    {
        $id = $maps->costCentres[trim($name)] ?? null;
        if ($id === null) {
            throw new ResolveException("Unknown cost centre “{$name}” (not present in the masters export).");
        }

        return $id;
    }

    private function reject(string $label, string $date, string $reason): void
    {
        $this->rejections[] = ['voucher' => $label, 'date' => $date, 'reason' => $reason];
    }

    private function label(array $v): string
    {
        $type = $v['type_raw'] !== '' ? $v['type_raw'] : 'Voucher';
        $num = $v['number_raw'] !== '' ? ' #'.$v['number_raw'] : '';

        return $type.$num.' dated '.$v['date'];
    }

    private function flatten(ValidationException $e): string
    {
        $msgs = [];
        foreach ($e->errors() as $field => $errors) {
            foreach ($errors as $m) {
                $msgs[] = $m;
            }
        }

        return implode(' ', $msgs) ?: $e->getMessage();
    }
}
