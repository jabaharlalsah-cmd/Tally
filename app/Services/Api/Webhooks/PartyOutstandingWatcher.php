<?php

namespace App\Services\Api\Webhooks;

use App\Models\Ledger;
use App\Services\BillService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16C — detect that a voucher changed a party's bill-wise outstanding, and by how much.
 *
 * THE TRIGGER IS "the voucher wrote bill_allocations", NOT "the voucher touched a Sundry
 * Debtor/Creditor". A non-bill-wise debtor is excluded from the Outstandings report entirely
 * (BillService::billWiseLedgersUnder filters on maintain_bill_by_bill), so a voucher hitting one
 * moves the ledger's CLOSING but changes no reported outstanding — emitting for it would announce
 * a change the customer cannot see anywhere in ZeroBook.
 *
 * THE CHEAP TRICK — previous = new − delta_new + delta_old.
 * BillService pins `ledger closing (Dr-terms) = Σ bill pending + opening`, and opening is a
 * constant for a given party, so Δclosing ≡ ΔΣpending. That means the PREVIOUS outstanding is
 * recoverable arithmetically from the NEW one plus this voucher's own signed contribution — no
 * before-snapshot of the whole book, and nothing expensive inside the accounting transaction.
 * Cost: 2 queries per voucher, independent of how many parties it touched.
 *
 * Never calls BillService::outstandings() — that pulls ledgerClosings() → an unbounded GROUP BY
 * over the entire book. Fine for a report screen, catastrophic on every voucher post.
 *
 * DEDUP falls out of the GROUP BY: allocations collapse to one row per ledger, so a Sales invoice
 * with twelve allocations against one party yields exactly ONE event.
 */
class PartyOutstandingWatcher
{
    public function __construct(private WebhookEmitter $emitter, private BillService $bills) {}

    /**
     * This voucher's signed Dr-terms contribution per party, from its CURRENT allocations.
     *
     * Called BEFORE an alter/cancel to capture the outgoing state (a plain SELECT — it cannot fail
     * the post), and again AFTER commit for the incoming state. Returns [ledgerId => paise].
     *
     * The sign convention is identical to BillService::bills() (Cr = negative, Dr = positive), so
     * the two compose exactly.
     */
    public function deltasFor(int $voucherId): array
    {
        if (! Schema::hasTable('webhook_subscriptions') || ! Schema::hasTable('bill_allocations')) {
            return [];
        }

        $rows = DB::table('bill_allocations as ba')
            ->join('voucher_entries as ve', 've.id', '=', 'ba.voucher_entry_id')
            ->where('ba.voucher_id', $voucherId)
            ->groupBy('ba.ledger_id')
            ->selectRaw("ba.ledger_id, SUM(CASE WHEN ve.dr_cr = 'Cr' THEN -ba.amount ELSE ba.amount END) as delta")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->ledger_id] = (int) round(((float) $r->delta) * 100);
        }

        return $out;
    }

    /**
     * Emit party.outstanding.changed for every party whose outstanding actually moved.
     *
     * @param  array  $deltaBefore  from deltasFor() BEFORE the write ([] for a create)
     * @param  int    $voucherId    the committed voucher (its allocations are gone after a cancel,
     *                              which is exactly why deltaBefore carries the cancel's state)
     */
    public function emitChanges(array $deltaBefore, int $voucherId, ?int $companyId): void
    {
        $deltaAfter = $this->deltasFor($voucherId);

        // Union: an alter can REMOVE a party (present before, absent after) — that party's
        // outstanding changed too, so it must still be reported.
        $ledgerIds = array_values(array_unique(array_merge(array_keys($deltaBefore), array_keys($deltaAfter))));

        if ($ledgerIds === []) {
            return;
        }

        $new = $this->pendingFor($ledgerIds);
        $names = Ledger::whereIn('id', $ledgerIds)->pluck('name', 'id');

        foreach ($ledgerIds as $lid) {
            $newP = $new[$lid] ?? 0;
            $dNew = $deltaAfter[$lid] ?? 0;   // 0 on a cancel (the allocations are gone)
            $dOld = $deltaBefore[$lid] ?? 0;  // 0 on a create

            // create: prev = new − dNew | alter: prev = new − dNew + dOld | cancel: prev = new + dOld
            $prevP = $newP - $dNew + $dOld;

            if ($prevP === $newP) {
                continue;   // fire only on an ACTUAL change
            }

            $this->emitter->emit(
                WebhookEvents::PARTY_OUTSTANDING_CHANGED,
                fn () => [
                    'party_ledger_id' => $lid,
                    'name' => $names[$lid] ?? null,
                    'previous_outstanding' => \App\Support\ApiMoney::fromPaise($prevP),
                    'new_outstanding' => \App\Support\ApiMoney::fromPaise($newP),
                    'voucher_id' => $voucherId,
                ],
                $companyId,
            );
        }
    }

    /** Σ bill pending per party, in Dr-terms paise. ONE query for all parties. */
    private function pendingFor(array $ledgerIds): array
    {
        $out = [];

        foreach ($this->bills->bills($ledgerIds) as $bill) {
            $lid = (int) $bill['ledger_id'];
            $out[$lid] = ($out[$lid] ?? 0) + (int) $bill['pending'];
        }

        return $out;
    }
}
