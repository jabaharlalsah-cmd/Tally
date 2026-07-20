<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CostingMethodLockedException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequiresIdempotencyKey;
use App\Http\Resources\Api\V1\StockItemResource;
use App\Models\StockItem;
use App\Services\Api\ApiVoucherException;
use App\Services\Api\IdempotencyService;
use App\Services\Api\IdempotentOperation;
use App\Support\ActiveCompany;
use App\Support\ApiError;
use App\Support\Api\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 16B — stock-item masters over REST.
 *
 * Validation mirrors StockItemWorkspace: company-scoped unique name; optional company-scoped
 * group/unit/godown; costing_method required and one of weighted_average|fifo|lifo; a fifo/lifo
 * item must open at zero. The costing_method LOCK is enforced by the StockItem::updating model
 * observer (it fires on any save), which throws CostingMethodLockedException — a plain exception
 * that would otherwise become a 500. We catch it and emit a 409 with the reason.
 */
class StockItemsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockItem::query()->with(['group', 'unit']);

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return response()->json(
            CursorPage::build($request, $query, fn (StockItem $i) => StockItemResource::make($i))
        );
    }

    public function show(StockItem $stockItem): JsonResponse
    {
        return response()->json(StockItemResource::make($stockItem->load(['group', 'unit'])));
    }

    public function store(Request $request, IdempotencyService $idem): JsonResponse
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);
        $idemKey = $request->attributes->get(RequiresIdempotencyKey::ATTR);
        $bodyHash = IdempotencyService::fingerprint($request);   // company-aware — see fingerprint()

        $outcome = $idem->run($key->id, $idemKey, $bodyHash, function () use ($request) {
            $data = $this->validated($request, null);

            $item = $this->guardCostingLock(fn () => StockItem::create($this->attributes($data)));

            return new IdempotentOperation(201, StockItemResource::make($item->load(['group', 'unit'])), 'stock_item', $item->id);
        });

        return $outcome->toResponse($request);
    }

    public function update(Request $request, StockItem $stockItem, IdempotencyService $idem): JsonResponse
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);
        $idemKey = $request->attributes->get(RequiresIdempotencyKey::ATTR);
        $bodyHash = IdempotencyService::fingerprint($request);   // company-aware — see fingerprint()

        $outcome = $idem->run($key->id, $idemKey, $bodyHash, function () use ($request, $stockItem) {
            $data = $this->validated($request, $stockItem);

            $this->guardCostingLock(function () use ($stockItem, $data) {
                $stockItem->update($this->attributes($data));
            });

            return new IdempotentOperation(200, StockItemResource::make($stockItem->fresh(['group', 'unit'])), 'stock_item', $stockItem->id);
        });

        return $outcome->toResponse($request);
    }

    /** The costing-lock backstop lives in the model; turn its exception into a clean 409. */
    private function guardCostingLock(callable $work)
    {
        try {
            return $work();
        } catch (CostingMethodLockedException $e) {
            throw new ApiVoucherException('conflict', 409, ['reason' => $e->getMessage()]);
        }
    }

    private function validated(Request $request, ?StockItem $ignore): array
    {
        $company = ActiveCompany::check();
        $data = $request->json()->all();

        $nameUnique = Rule::unique('stock_items', 'name')->where('company_id', $company);
        if ($ignore) {
            $nameUnique->ignore($ignore->id);
        }

        return validator($data, [
            'name' => ['required', 'string', 'max:191', $nameUnique],
            'stock_group_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', $company)],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $company)],
            'opening_qty' => ['nullable', 'numeric'],
            'opening_rate' => ['nullable', 'numeric', 'min:0'],
            'opening_godown_id' => ['nullable', 'integer', Rule::exists('godowns', 'id')->where('company_id', $company)],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hsn_sac' => ['nullable', 'string', 'max:30'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'costing_method' => ['required', Rule::in(['weighted_average', 'fifo', 'lifo'])],
        ])->validate();
    }

    private function attributes(array $data): array
    {
        return array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'stock_group_id' => $data['stock_group_id'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'opening_qty' => $data['opening_qty'] ?? null,
            'opening_rate' => $data['opening_rate'] ?? null,
            'opening_godown_id' => $data['opening_godown_id'] ?? null,
            'gst_rate' => $data['gst_rate'] ?? null,
            'hsn_sac' => $data['hsn_sac'] ?? null,
            'reorder_level' => $data['reorder_level'] ?? null,
            'costing_method' => $data['costing_method'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
