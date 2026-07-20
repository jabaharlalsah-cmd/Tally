<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\Voucher;
use App\Services\Api\ApiVoucherException;
use App\Services\BalanceService;
use App\Services\BillService;
use App\Support\ApiMoney;
use App\Support\ScenarioContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 16B — read-only reports over REST.
 *
 * Every figure is produced by the SAME service the UI reports use (BalanceService, BillService),
 * so an API figure equals the on-screen figure by construction — the controller only reshapes it
 * for the wire and never recomputes. The services carry integer paise (Dr-terms); the API emits
 * decimal strings via ApiMoney, so the numeric value is preserved exactly.
 *
 * All reports are real-books-only: the services apply ScenarioContext internally (default = no
 * scenario in view = scenario_id IS NULL), and the day book applies it explicitly. No parameter
 * can surface a provisional voucher.
 */
class ReportsController extends Controller
{
    /**
     * A date query param, validated. A malformed value is a CLIENT error → 400, not an uncaught
     * Carbon parse exception → 500. Returns the original string (for withinFy) or null.
     */
    private function dateParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            Carbon::parse($value);
        } catch (\Throwable) {
            throw new ApiVoucherException('bad_request', 400, [$key => 'Malformed date; use YYYY-MM-DD.']);
        }

        return (string) $value;
    }

    /** GET /v1/reports/trial-balance?as_of= */
    public function trialBalance(Request $request, BalanceService $balances): JsonResponse
    {
        [$from, $to] = $balances->withinFy(null, $this->dateParam($request, 'as_of'));
        $tb = $balances->trialBalance($from, $to);

        $rows = [];
        foreach ($balances->ledgerBalances($from, $to) as $l) {
            $rows[] = [
                'ledger_id' => $l['id'],
                'name' => $l['name'],
                'group_id' => $l['group_id'],
                'opening' => ApiMoney::fromPaise($l['opening']),
                'net' => ApiMoney::fromPaise($l['net']),
                'closing' => ApiMoney::fromPaise($l['closing']),
                'closing_side' => BalanceService::drcr($l['closing']),
            ];
        }

        return response()->json([
            'as_of' => $to->toDateString(),
            'from' => $from->toDateString(),
            'ledgers' => $rows,
            'total_dr' => ApiMoney::fromPaise($tb['total_dr']),
            'total_cr' => ApiMoney::fromPaise($tb['total_cr']),
            'balanced' => $tb['balanced'],
        ]);
    }

    /** GET /v1/reports/ledger-balance/{ledger}?as_of= */
    public function ledgerBalance(Request $request, Ledger $ledger, BalanceService $balances): JsonResponse
    {
        [$from, $to] = $balances->withinFy(null, $this->dateParam($request, 'as_of'));
        $data = $balances->ledgerVouchers($ledger->id, $from, $to);

        return response()->json([
            'ledger_id' => $ledger->id,
            'name' => $ledger->name,
            'as_of' => $to->toDateString(),
            'opening' => ApiMoney::fromPaise($data['opening']),
            'closing' => ApiMoney::fromPaise($data['closing']),
            'closing_side' => BalanceService::drcr($data['closing']),
            'movement' => ApiMoney::fromPaise($data['closing'] - $data['opening']),
        ]);
    }

    /**
     * GET /v1/reports/party-outstanding/{ledger}?as_of=
     *
     * The party's bill-wise open bills. `nature` is derived from the ledger's own group (Sundry
     * Debtors → receivable, Sundry Creditors → payable) so the client cannot mislabel it.
     */
    public function partyOutstanding(Request $request, Ledger $ledger, BillService $bills): JsonResponse
    {
        $asOfStr = $this->dateParam($request, 'as_of'); $asOf = $asOfStr ? Carbon::parse($asOfStr) : Carbon::today();
        $groupName = $ledger->group?->name;
        $nature = $groupName === 'Sundry Creditors' ? 'payable' : 'receivable';

        $data = $bills->outstandings($nature, $asOf);

        // Narrow the full-group report to just this party.
        $party = collect($data['parties'])->firstWhere('ledger_id', $ledger->id);

        $rows = $party ? array_map(fn ($r) => [
            'bill_ref' => $r['ref_name'] ?? null,
            'bill_date' => ($r['bill_date'] ?? '') !== '' ? $r['bill_date'] : null,
            'due_date' => ($r['due_date'] ?? '') !== '' ? $r['due_date'] : null,
            // 'original' is already a formatted money string from the service; 'pending' we render
            // from the SIGNED paise so the +/- (Dr/Cr) survives.
            'original' => $r['original'] ?? null,
            'pending' => isset($r['pending_signed']) ? ApiMoney::fromPaise($r['pending_signed']) : null,
        ], $party['rows'] ?? []) : [];

        return response()->json([
            'ledger_id' => $ledger->id,
            'name' => $ledger->name,
            'nature' => $nature,
            'as_of' => $asOf->toDateString(),
            'pending_total' => ApiMoney::fromPaise($party['pending_signed'] ?? 0),
            'on_account' => ApiMoney::fromPaise($party['on_account_signed'] ?? 0),
            'bills' => $rows,
        ]);
    }

    /** GET /v1/reports/day-book?date= — the day's vouchers. */
    public function dayBook(Request $request): JsonResponse
    {
        $dateStr = $this->dateParam($request, 'date'); $date = $dateStr ? Carbon::parse($dateStr) : Carbon::today();

        // The SAME query the DayBook component runs — ScenarioContext keeps it real-books-only.
        $vouchers = Voucher::query()
            ->with(['entries.ledger'])
            ->whereDate('date', $date)
            ->tap(fn ($q) => ScenarioContext::apply($q, 'vouchers'))
            ->orderBy('date')->orderBy('id')
            ->get();

        $rows = $vouchers->map(function (Voucher $v) {
            $totalP = 0;
            foreach ($v->entries as $e) {
                if ($e->dr_cr === 'Dr') {
                    $totalP += ApiMoney::toPaise((string) $e->amount);
                }
            }

            return [
                'id' => $v->id,
                'type' => $v->type,
                'type_label' => Voucher::TYPES[$v->type]['label'] ?? ucfirst($v->type),
                'number' => (int) $v->number,
                'display_number' => $v->displayNumber(),
                'date' => $v->date->toDateString(),
                'narration' => $v->narration,
                'amount' => ApiMoney::fromPaise($totalP),
            ];
        });

        return response()->json([
            'date' => $date->toDateString(),
            'vouchers' => $rows->values()->all(),
            'count' => $rows->count(),
        ]);
    }
}
