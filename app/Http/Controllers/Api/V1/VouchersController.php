<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequiresIdempotencyKey;
use App\Http\Resources\Api\V1\VoucherResource;
use App\Livewire\VoucherScreen;
use App\Models\Voucher;
use App\Services\Api\IdempotencyService;
use App\Services\Api\IdempotentOperation;
use App\Services\Api\VoucherPayloadBuilder;
use App\Support\ApiError;
use App\Support\Api\CursorPage;
use App\Support\TenantGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16B — vouchers over REST.
 *
 * THE CARDINAL RULE, in code: every write ultimately calls `(new VoucherScreen)->post($payload)`
 * — the exact method the UI, the Tally importer, and the desktop sync engine all call. The
 * controller only TRANSLATES (API shape ↔ internal payload) and wraps the write in idempotency.
 * All accounting authority — balance, GST/VAT/TDS re-verification, bill-wise, stock/lot, cost
 * preservation, sync-outbox — lives in that shared path and is inherited, never re-implemented.
 */
class VouchersController extends Controller
{
    private const EAGER = ['entries.ledger', 'stockEntries.stockItem', 'stockEntries.godown', 'billAllocations'];

    /** POST /v1/vouchers — create. Idempotent. */
    public function store(Request $request, VoucherPayloadBuilder $builder, IdempotencyService $idem): JsonResponse
    {
        return $this->write($request, $idem, function () use ($request, $builder) {
            $payload = $builder->build($this->body($request));
            $voucher = $this->post($payload);

            return new IdempotentOperation(201, VoucherResource::make($voucher), 'voucher', $voucher->id);
        });
    }

    /** GET /v1/vouchers/{voucher} — read. Cross-company id 404s via the BelongsToCompany scope. */
    public function show(Voucher $voucher): JsonResponse
    {
        $this->refuseScenario($voucher);

        return response()->json(VoucherResource::make($voucher->load(self::EAGER)));
    }

    /** GET /v1/vouchers — cursor-paginated list. Real vouchers only (structural, not a parameter). */
    public function index(Request $request): JsonResponse
    {
        $query = Voucher::query()
            ->whereNull('scenario_id')   // real books only — never client-controllable
            ->with(self::EAGER);

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }
        if ($party = $request->query('party_ledger_id')) {
            $query->where('party_ledger_id', (int) $party);
        }

        return response()->json(
            CursorPage::build($request, $query, fn (Voucher $v) => VoucherResource::make($v))
        );
    }

    /** PUT /v1/vouchers/{voucher} — full-replacement alter through the shared path. Idempotent. */
    public function update(Request $request, Voucher $voucher, VoucherPayloadBuilder $builder, IdempotencyService $idem): JsonResponse
    {
        $this->refuseScenario($voucher);

        return $this->write($request, $idem, function () use ($request, $builder, $voucher) {
            $payload = $builder->build($this->body($request));
            $payload['voucher_id'] = $voucher->id;   // route to persistAlter through the same post()
            $altered = $this->post($payload);

            return new IdempotentOperation(200, VoucherResource::make($altered), 'voucher', $altered->id);
        });
    }

    /**
     * POST /v1/vouchers/{voucher}/cancel — cancel via the shared path. Idempotent.
     *
     * The id is a plain path param, NOT a bound model: cancel HARD-deletes the voucher (the
     * model's delete hooks reverse stock/TDS/lots), so a bound {voucher} would 404 at route
     * binding on a same-key retry before idempotency could replay the stored 200. Loading it
     * manually inside the idempotent operation lets the retry replay correctly.
     */
    public function cancel(Request $request, string $voucher, IdempotencyService $idem): JsonResponse
    {
        return $this->write($request, $idem, function () use ($voucher) {
            // Company-scoped by the global scope; whereNull matches the real-books-only {voucher}
            // binding used by show/update, so a scenario voucher id is "not found" here too.
            $v = Voucher::whereNull('scenario_id')->find((int) $voucher);

            if (! $v) {
                // Not found (or another company's, or a scenario voucher) — surface as 404.
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
            }

            $id = $v->id;
            $display = $v->displayNumber();

            // Drive the real cancel method headlessly: editVoucherId is public, and cancelVoucher()
            // reads it. This is the identical path the UI uses — the model's deleting/deleted hooks
            // do the lot refold and reversal.
            $screen = new VoucherScreen;
            $screen->editVoucherId = $id;
            $result = $screen->cancelVoucher();

            if (! ($result['ok'] ?? false)) {
                throw new \App\Services\Api\ApiVoucherException('conflict', 409, ['reason' => $result['message'] ?? 'Could not cancel.']);
            }

            return new IdempotentOperation(200, [
                'id' => $id,
                'display_number' => $display,
                'status' => 'cancelled',
                'message' => $result['message'] ?? ('Cancelled '.$display),
            ], 'voucher', $id);
        });
    }

    // ── shared write plumbing ───────────────────────────────────────────────────────────

    /**
     * Wrap a write in idempotency. post()'s own ValidationException (balance/gst/tds/… keys) and
     * TenantGate's 'tenant' key both bubble out of the operation → the row is released → the
     * global renderer maps them to the envelope. A ValidationException carrying only 'tenant'
     * (a suspended tenant reaching the write gate) is remapped to the tenant_not_active 403 shape
     * so the API contract stays stable.
     */
    private function write(Request $request, IdempotencyService $idem, callable $operation): JsonResponse
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);
        $idemKey = $request->attributes->get(RequiresIdempotencyKey::ATTR);
        $bodyHash = IdempotencyService::fingerprint($request);   // company-aware — see fingerprint()

        try {
            $outcome = $idem->run($key->id, $idemKey, $bodyHash, $operation);
        } catch (ValidationException $e) {
            if (array_keys($e->errors()) === ['tenant']) {
                return ApiError::response('tenant_not_active', 403, $e->errors()['tenant'][0] ?? null, request: $request);
            }
            throw $e;
        }

        return $outcome->toResponse($request);
    }

    /** Post through the shared path and reload the voucher with its full graph. */
    private function post(array $payload): Voucher
    {
        $result = (new VoucherScreen)->post($payload);

        return Voucher::with(self::EAGER)->findOrFail($result['voucher']['id']);
    }

    /**
     * The API is real-books-only: a provisional (scenario) voucher does not exist as far as the
     * API is concerned, so reading or altering one by id is a 404 — the same answer a cross-company
     * id gets. Lists and reports already exclude scenario vouchers structurally; this closes the
     * direct-by-id path. (scenario_id is not a company-scope column, so binding resolves it; the
     * check is explicit here.)
     */
    private function refuseScenario(Voucher $voucher): void
    {
        if ($voucher->scenario_id !== null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
        }
    }

    /** The parsed JSON body, or [] for an empty body (which validation then rejects with a 422). */
    private function body(Request $request): array
    {
        $data = $request->json()->all();

        return is_array($data) ? $data : [];
    }
}
