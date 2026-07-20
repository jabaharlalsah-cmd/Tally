<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequiresIdempotencyKey;
use App\Http\Resources\Api\V1\LedgerResource;
use App\Models\AccountGroup;
use App\Models\Ledger;
use App\Services\Api\IdempotencyService;
use App\Services\Api\IdempotentOperation;
use App\Support\ActiveCompany;
use App\Support\ApiError;
use App\Support\ApiMoney;
use App\Support\Api\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16B — ledger masters over REST.
 *
 * Validation MIRRORS LedgerWorkspace::saveSingle/saveAlter exactly (company-scoped unique name,
 * company-scoped group, opening_balance_type required only when opening > 0, the deductee_type
 * enum, deductee_pan upper-cased+trimmed). The lock rules are the UI's, not invented: a reserved
 * ledger cannot be renamed or regrouped, and the P&L ledger cannot be regrouped. Ledgers do NOT
 * lock on transaction usage — the UI has no such rule, so neither does the API.
 */
class LedgersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Ledger::query()->with('group');

        if ($group = $request->query('group')) {
            $query->whereHas('group', fn ($q) => $q->where('name', $group));
        }
        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return response()->json(
            CursorPage::build($request, $query, fn (Ledger $l) => LedgerResource::make($l))
        );
    }

    public function show(Ledger $ledger): JsonResponse
    {
        return response()->json(LedgerResource::make($ledger->load('group')));
    }

    public function store(Request $request, IdempotencyService $idem): JsonResponse
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);
        $idemKey = $request->attributes->get(RequiresIdempotencyKey::ATTR);
        $bodyHash = IdempotencyService::fingerprint($request);   // company-aware — see fingerprint()

        $outcome = $idem->run($key->id, $idemKey, $bodyHash, function () use ($request) {
            $data = $this->validated($request, null);
            $ledger = Ledger::create($this->attributes($data));

            return new IdempotentOperation(201, LedgerResource::make($ledger->load('group')), 'ledger', $ledger->id);
        });

        return $outcome->toResponse($request);
    }

    public function update(Request $request, Ledger $ledger, IdempotencyService $idem): JsonResponse
    {
        $key = $request->attributes->get(ApiError::API_KEY_ATTR);
        $idemKey = $request->attributes->get(RequiresIdempotencyKey::ATTR);
        $bodyHash = IdempotencyService::fingerprint($request);   // company-aware — see fingerprint()

        $outcome = $idem->run($key->id, $idemKey, $bodyHash, function () use ($request, $ledger) {
            $data = $this->validated($request, $ledger);
            $attrs = $this->attributes($data);

            // UI lock rules (LedgerWorkspace::saveAlter): a reserved ledger keeps its name; a
            // reserved OR P&L ledger keeps its group.
            if ($ledger->is_reserved) {
                unset($attrs['name']);
            }
            if ($ledger->is_reserved || $ledger->is_pl_account) {
                unset($attrs['group_id']);
            }

            $ledger->update($attrs);

            return new IdempotentOperation(200, LedgerResource::make($ledger->fresh('group')), 'ledger', $ledger->id);
        });

        return $outcome->toResponse($request);
    }

    /** Rules copied 1:1 from LedgerWorkspace. $ignore = the ledger being updated (skip self on unique). */
    private function validated(Request $request, ?Ledger $ignore): array
    {
        $company = ActiveCompany::check();
        $data = $request->json()->all();

        // Accept a group by name as well as by id (the brief allows either).
        if (empty($data['group_id']) && ! empty($data['group_name'])) {
            $data['group_id'] = AccountGroup::where('company_id', $company)->where('name', $data['group_name'])->value('id');
        }

        $nameUnique = Rule::unique('ledgers', 'name')->where('company_id', $company);
        if ($ignore) {
            $nameUnique->ignore($ignore->id);
        }

        return validator($data, [
            'name' => ['required', 'string', 'max:191', $nameUnique],
            'group_id' => ['required', 'integer', Rule::exists('account_groups', 'id')->where('company_id', $company)],
            'opening_balance' => ['nullable', function ($attr, $value, $fail) {
                if ($value !== null && ! ApiMoney::isValid($value)) {
                    $fail('The opening_balance must be a decimal string, e.g. "0.00".');
                }
            }],
            'opening_balance_type' => [Rule::requiredIf(fn () => $this->openingPaise($data) > 0), 'nullable', Rule::in(['Dr', 'Cr'])],
            'pan' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hsn_sac' => ['nullable', 'string', 'max:20'],
            'gst_registration_type' => ['nullable', 'string', 'max:30'],
            'deductee_pan' => ['nullable', 'string', 'max:10'],
            'deductee_type' => ['nullable', Rule::in(['individual_huf', 'company_firm_llp', 'other'])],
            'default_tds_section_id' => ['nullable', 'integer', Rule::exists('tds_sections', 'id')->where('company_id', $company)],
        ], [], ['group_id' => 'group'])->validate();
    }

    /** Map validated data → model attributes, matching the UI's normalization exactly. */
    private function attributes(array $data): array
    {
        $openingPaise = $this->openingPaise($data);

        return array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'group_id' => $data['group_id'] ?? null,
            'opening_balance' => ApiMoney::forPost($openingPaise),
            'opening_balance_type' => $openingPaise > 0 ? ($data['opening_balance_type'] ?? null) : null,
            'pan' => $data['pan'] ?? null,
            'gstin' => $data['gstin'] ?? null,
            'gst_rate' => $data['gst_rate'] ?? null,
            'hsn_sac' => $data['hsn_sac'] ?? null,
            'gst_registration_type' => $data['gst_registration_type'] ?? null,
            // deductee_pan is DISTINCT from pan and is stored upper-cased + trimmed (the TDS engine
            // keys on it; its absence triggers the 206AA no-PAN rate).
            'deductee_pan' => ! empty($data['deductee_pan']) ? strtoupper(trim($data['deductee_pan'])) : null,
            'deductee_type' => $data['deductee_type'] ?? null,
            'default_tds_section_id' => $data['default_tds_section_id'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function openingPaise(array $data): int
    {
        $v = $data['opening_balance'] ?? null;

        return ($v !== null && ApiMoney::isValid($v)) ? ApiMoney::toPaise($v) : 0;
    }
}
