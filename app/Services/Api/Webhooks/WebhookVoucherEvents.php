<?php

namespace App\Services\Api\Webhooks;

use App\Http\Resources\Api\V1\VoucherResource;
use App\Models\Voucher;
use App\Services\TallyImport\BulkMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 16C — the voucher emission points, in ONE place.
 *
 * This exists so VoucherScreen — the shared, proven posting path that 33 proofs depend on — gains
 * only a handful of obviously-safe lines instead of webhook logic. Every method here is
 * total: it swallows its own failures and returns a benign value, so no call site can be broken by
 * a webhook problem. That is Cardinal Rule 2 enforced at the boundary rather than trusted.
 *
 * WHAT IS DELIBERATELY NOT EMITTED:
 *
 *  • BulkMode (the Tally importer). A 50k-voucher migration is a data load, not fifty thousand
 *    real-time business events — and under BulkMode persistNew writes into the importer's own
 *    surrounding transaction, whose dry-run mode posts everything and then rolls it all back.
 *  • Provisional (scenario) vouchers. They are not the real books. They are also invisible to
 *    BillService::bills() in the default context, so an outstanding delta computed for one would
 *    read 0 and report a phantom change.
 */
class WebhookVoucherEvents
{
    /** Voucher types whose posting IS a payment in the customer's books. */
    private const PAYMENT_TYPES = ['receipt', 'payment'];

    public function __construct(
        private WebhookEmitter $emitter,
        private PartyOutstandingWatcher $watcher,
    ) {}

    /**
     * BEFORE an alter/cancel: capture the voucher's outgoing bill contribution.
     *
     * Needed because an alter REPLACES the allocations and a cancel CASCADES them away — after
     * commit the old state is unrecoverable, so `previous` could not be computed. This is a plain
     * SELECT (it cannot fail the post), and it is skipped entirely when nobody is listening.
     *
     * @return array [ledgerId => paise], or [] for a create / when unwanted
     */
    public function captureBefore(?int $voucherId): array
    {
        try {
            if ($voucherId === null || BulkMode::isActive()) {
                return [];
            }

            if (! $this->emitter->wants(WebhookEvents::PARTY_OUTSTANDING_CHANGED)) {
                return [];   // no subscriber — do not pay for the query
            }

            return $this->watcher->deltasFor($voucherId);
        } catch (Throwable $e) {
            $this->swallow('webhook.capture_before_failed', $e);

            return [];
        }
    }

    /**
     * AFTER a create/alter write returns. Registers every emission through DB::afterCommit, so
     * nothing fires unless the voucher actually commits — and the delivery-row INSERT lands
     * outside the accounting transaction, where it cannot roll the voucher back.
     */
    public function afterWrite(Voucher $voucher, bool $isAlter, array $deltaBefore): void
    {
        try {
            if (BulkMode::isActive() || $voucher->scenario_id !== null) {
                return;
            }

            $id = $voucher->id;
            $companyId = $voucher->company_id;
            $type = $voucher->type;

            $this->emitter->afterCommit(
                $isAlter ? WebhookEvents::VOUCHER_ALTERED : WebhookEvents::VOUCHER_CREATED,
                fn () => $this->voucherPayload($id),
                $companyId,
            );

            // payment.recorded is a SPECIALISATION of the same committed voucher — the customer's
            // books, never ZeroBook's subscription billing (that Payment model is central and is
            // not their money).
            if (in_array($type, self::PAYMENT_TYPES, true)) {
                $this->emitter->afterCommit(
                    WebhookEvents::PAYMENT_RECORDED,
                    fn () => $this->voucherPayload($id),
                    $companyId,
                );
            }

            if ($this->emitter->wants(WebhookEvents::PARTY_OUTSTANDING_CHANGED, $companyId)) {
                DB::afterCommit(function () use ($deltaBefore, $id, $companyId) {
                    try {
                        $this->watcher->emitChanges($deltaBefore, $id, $companyId);
                    } catch (Throwable $e) {
                        $this->swallow('webhook.outstanding_emit_failed', $e);
                    }
                });
            }
        } catch (Throwable $e) {
            $this->swallow('webhook.after_write_failed', $e);
        }
    }

    /**
     * BEFORE a cancel: snapshot everything the event will need, because the voucher row and its
     * allocations are about to be hard-deleted.
     *
     * Mirrors the codebase's own precedent two lines above the delete — cancelVoucher() already
     * captures `$label = $v->displayNumber()` before the row disappears for exactly this reason.
     *
     * @return array|null  the context to hand afterCancel(), or null when nothing should emit
     */
    public function beforeCancel(Voucher $voucher): ?array
    {
        try {
            if (BulkMode::isActive() || $voucher->scenario_id !== null) {
                return null;
            }

            return [
                'id' => $voucher->id,
                'company_id' => $voucher->company_id,
                // The payload as it was while the voucher still existed.
                'payload' => $this->voucherPayload($voucher->id),
                'delta_before' => $this->emitter->wants(WebhookEvents::PARTY_OUTSTANDING_CHANGED, $voucher->company_id)
                    ? $this->watcher->deltasFor($voucher->id)
                    : [],
            ];
        } catch (Throwable $e) {
            $this->swallow('webhook.before_cancel_failed', $e);

            return null;
        }
    }

    /** AFTER the cancel transaction. Emits the pre-captured snapshot, post-commit. */
    public function afterCancel(?array $ctx): void
    {
        try {
            if ($ctx === null) {
                return;
            }

            $payload = $ctx['payload'];
            $companyId = $ctx['company_id'];
            $id = $ctx['id'];

            $this->emitter->afterCommit(
                WebhookEvents::VOUCHER_CANCELLED,
                fn () => $payload === null ? null : $payload + ['status' => 'cancelled'],
                $companyId,
            );

            if ($ctx['delta_before'] !== []) {
                DB::afterCommit(function () use ($ctx, $id, $companyId) {
                    try {
                        // deltasFor() now returns [] (the rows cascaded away), so the watcher's
                        // uniform formula reduces to previous = new + delta_before.
                        $this->watcher->emitChanges($ctx['delta_before'], $id, $companyId);
                    } catch (Throwable $e) {
                        $this->swallow('webhook.outstanding_emit_failed', $e);
                    }
                });
            }
        } catch (Throwable $e) {
            $this->swallow('webhook.after_cancel_failed', $e);
        }
    }

    /** The event payload: the same voucher shape 16B's GET /vouchers/{id} returns. */
    private function voucherPayload(int $voucherId): ?array
    {
        $voucher = Voucher::with(['entries.ledger', 'stockEntries.stockItem', 'stockEntries.godown', 'billAllocations'])
            ->find($voucherId);

        return $voucher ? VoucherResource::make($voucher) : null;
    }

    /** Class + message only — never the exception body, which could quote the voucher. */
    private function swallow(string $key, Throwable $e): void
    {
        Log::warning($key, ['exception' => $e::class, 'message' => $e->getMessage()]);
    }
}
