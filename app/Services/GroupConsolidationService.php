<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Currency;
use App\Models\StockLot;
use App\Models\Ledger;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12C-2 — THE consolidation engine: Group Trial Balance / Balance Sheet /
 * P&L for one company group, with two elimination layers. READ-ONLY — nothing is
 * ever posted; the individual books are untouched (the audit requirement).
 *
 *  • COMPLETE elimination (12B tags): every voucher tagged inter-company whose
 *    BOTH sides are members of the group being consolidated has its
 *    voucher_entries contributions removed per (company, ledger) — inter-company
 *    sales/purchases vanish from the group P&L, the A→B receivable and B→A
 *    payable cancel on the group Balance Sheet. Only TAGGED vouchers eliminate;
 *    an untagged inter-group voucher (pre-12B data — or a forex revaluation
 *    journal, which is tag-exempt by design) is SURFACED in "Unaccounted", never
 *    silently double-counted.
 *
 *  • UNREALISED-PROFIT elimination (12C-1 lots): for inter-company inventory
 *    still on hand, Σ (received_rate − source_cost) × remaining. Unmatched lots
 *    (source_cost null — 12C-1's honest boundary) are LISTED, not eliminated:
 *    a visible gap beats a silent misvaluation.
 *
 * THE ARITHMETIC SPINE — one foundation, three reports: per member, adjusted
 * ledger rows = BalanceService::ledgerBalances() − that ledger's tagged
 * contributions. The TB rolls adjusted rows up by account-group NAME (the
 * cross-company identity) and recomputes Dr/Cr from them, so a one-sided tagged
 * flow SHOWS as an imbalance with a mismatch note. The P&L applies 6D's exact
 * stock math over adjusted rows with opening/closing stock reduced by
 * unrealised(from)/unrealised(to) — hence GROUP NET = Σ individual nets −
 * Δunrealised, exposed as a self-asserting tie check. The BS adds the group
 * stock injection, the group net, and a "Consolidation Adjustments" equity line
 * of −unrealised(from) (the prior-period portion) — balanced by construction.
 * (No seeded Retained Earnings LEDGER exists in the chart — Reserves & Surplus
 * is a group — so the adjustment is an explicit report line, documented.)
 *
 * Cross-company reads use the established 12B/12C-1 pattern ONLY:
 * withoutGlobalScope('company') + explicit whereIn(company_id, members).
 */
class GroupConsolidationService
{
    /**
     * Per-request memo (review fix). One render calls the pipeline 3–4× —
     * groupBalanceSheet → groupProfitAndLoss, each re-deriving completeEliminations
     * / adjustedLedgerRows / unrealisedProfitElimination over the whole book. These
     * are pure functions of (group, dates), so cache them for the request. The
     * service is resolved per app() call, so the cache never crosses requests.
     */
    private array $memo = [];

    private function once(string $key, callable $compute): mixed
    {
        return $this->memo[$key] ??= $compute();
    }

    /** @return int[] the group's member company ids */
    public function memberIds(CompanyGroup $group): array
    {
        return $group->companies()->pluck('companies.id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Mixed-base-currency guard: consolidation assumes one shared base currency.
     * Returns an error string naming the currencies, or null when consistent.
     */
    public function currencyMismatch(CompanyGroup $group): ?string
    {
        $members = Company::whereIn('id', $this->memberIds($group))->get();
        $baseIds = $members->pluck('base_currency_id')->filter()->map(fn ($i) => (int) $i)->all();

        $codes = $baseIds === []
            ? collect()
            : Currency::withoutGlobalScope('company')->whereIn('id', $baseIds)->pluck('code')->unique()->values();

        if ($codes->count() > 1) {
            return 'This group mixes base currencies ('.$codes->implode(', ').') — cross-currency consolidation '
                .'needs period-end translation mechanics and is not supported yet. Align the base currencies first.';
        }

        return null;
    }

    /**
     * COMPLETE eliminations — the tagged-voucher contributions per (company,
     * ledger), summed signed in Dr-terms paise, within [from..to] (from = null
     * means "since the beginning of the books" — the Balance-Sheet basis).
     *
     * @return array{byLedger: array<string,int>, categories: array<string,int>,
     *               dr_total: int, cr_total: int, voucher_count: int, mismatch: int}
     */
    public function completeEliminations(CompanyGroup $group, ?Carbon $from, Carbon $to): array
    {
        $key = 'ce:'.$group->id.':'.($from?->toDateString() ?? 'start').':'.$to->toDateString();

        return $this->once($key, fn () => $this->computeCompleteEliminations($group, $from, $to));
    }

    private function computeCompleteEliminations(CompanyGroup $group, ?Carbon $from, Carbon $to): array
    {
        $members = $this->memberIds($group);

        $rows = VoucherEntry::withoutGlobalScope('company')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->join('voucher_intercompany_tags as ict', 'ict.voucher_id', '=', 'vouchers.id')
            ->whereIn('voucher_entries.company_id', $members)
            ->whereIn('ict.counterparty_company_id', $members) // both sides in THIS group
            ->when($from !== null, fn ($q) => $q->where('vouchers.date', '>=', $from->toDateString()))
            ->where('vouchers.date', '<=', $to->toDateString())
            ->whereNull('vouchers.scenario_id') // REAL BOOKS ONLY — scenarios never widen consolidation
            ->groupBy('voucher_entries.company_id', 'voucher_entries.ledger_id')
            ->select('voucher_entries.company_id', 'voucher_entries.ledger_id')
            ->selectRaw("SUM(CASE WHEN voucher_entries.dr_cr = 'Dr' THEN voucher_entries.amount ELSE -voucher_entries.amount END) AS net")
            ->get();

        $voucherCount = (int) VoucherEntry::withoutGlobalScope('company')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->join('voucher_intercompany_tags as ict', 'ict.voucher_id', '=', 'vouchers.id')
            ->whereIn('voucher_entries.company_id', $members)
            ->whereIn('ict.counterparty_company_id', $members)
            ->when($from !== null, fn ($q) => $q->where('vouchers.date', '>=', $from->toDateString()))
            ->where('vouchers.date', '<=', $to->toDateString())
            ->whereNull('vouchers.scenario_id') // REAL BOOKS ONLY — scenarios never widen consolidation
            ->distinct()->count('vouchers.id');

        // Classify per ledger for the adjustments panel. Cross-company ledger read —
        // explicit member pin, per the established pattern.
        $ledgerIds = $rows->pluck('ledger_id')->map(fn ($i) => (int) $i)->all();
        $ledgers = $ledgerIds === [] ? collect() : Ledger::withoutGlobalScope('company')
            ->with(['group' => fn ($q) => $q->withoutGlobalScope('company')])
            ->whereIn('company_id', $members)->whereIn('id', $ledgerIds)->get()->keyBy('id');

        $byLedger = [];
        $cat = ['sales' => 0, 'purchases' => 0, 'receivables' => 0, 'payables' => 0, 'other' => 0];
        $drTotal = 0;
        $crTotal = 0;
        // Phase 12C-2 (review fix) — the SIGNED sum of eliminations on LINKED party
        // ledgers. Across a symmetric group each inter-company balance nets to zero:
        // A's receivable from B (a Dr on A's linked debtor) and B's payable to A (a
        // Cr on B's linked creditor) are equal and opposite. A NON-ZERO total means
        // an inter-company balance was posted on ONE side only (e.g. A recorded a
        // settlement/receipt B never booked) — a real asymmetry the earlier
        // dr_total−cr_total check could NEVER surface (every eliminated voucher is
        // internally Dr=Cr, so that difference is identically zero by construction).
        $linkedImbalance = 0;

        foreach ($rows as $r) {
            $net = (int) round(((float) $r->net) * 100); // Dr-terms paise
            $byLedger[$r->company_id.':'.$r->ledger_id] = ($byLedger[$r->company_id.':'.$r->ledger_id] ?? 0) + $net;
            if ($net > 0) {
                $drTotal += $net;
            } else {
                $crTotal += -$net;
            }

            $ledger = $ledgers->get((int) $r->ledger_id);
            $rootName = $this->rootNameFor($ledger);
            $nature = $this->natureFor($ledger);
            if ($ledger && $ledger->linked_company_id) {
                $linkedImbalance += $net;
                // Classify by the ledger's NATURE, not the entry sign: an asset-side
                // linked ledger is a receivable, a liability-side one a payable.
                $cat[$nature === 'Liabilities' ? 'payables' : 'receivables'] += abs($net);
            } elseif ($rootName === 'Sales Accounts') {
                $cat['sales'] += abs($net);
            } elseif ($rootName === 'Purchase Accounts') {
                $cat['purchases'] += abs($net);
            } else {
                $cat['other'] += abs($net);
            }
        }

        return [
            'byLedger' => $byLedger,
            'categories' => $cat,
            'dr_total' => $drTotal,
            'cr_total' => $crTotal,
            // A real, always-valid asymmetry signal (see above). 0 = every
            // inter-company balance has both sides posted; non-zero = one-sided.
            'mismatch' => $linkedImbalance,
            'voucher_count' => $voucherCount,
        ];
    }

    /**
     * UNREALISED-PROFIT elimination as of a date, from 12C-1's period-aware
     * replay. Matched lots eliminate (received − source) × remaining; unmatched
     * lots are listed with their at-receipt value and eliminate NOTHING.
     *
     * @return array{total: int, items: array, unmatched: array, unmatched_value: int}
     */
    public function unrealisedProfitElimination(CompanyGroup $group, Carbon $asOf): array
    {
        $key = 'upe:'.$group->id.':'.$asOf->toDateString();

        return $this->once($key, fn () => $this->computeUnrealisedProfitElimination($group, $asOf));
    }

    private function computeUnrealisedProfitElimination(CompanyGroup $group, Carbon $asOf): array
    {
        $members = $this->memberIds($group);

        // Which (receiving company, item) pairs have group-internal lots at all —
        // the one cross-company index scan; the replay itself runs per company
        // under runAs (reusing 12C-1's scoped, self-healing query verbatim).
        $pairs = StockLot::withoutGlobalScope('company')
            ->whereIn('company_id', $members)
            ->whereIn('source_company_id', $members)
            ->distinct()
            ->get(['company_id', 'stock_item_id']);

        $lotSvc = app(StockLotService::class);
        $companyNames = Company::whereIn('id', $members)->pluck('name', 'id');

        $total = 0;
        $items = [];
        $unmatched = [];
        $unmatchedValue = 0;

        foreach ($pairs as $pair) {
            $entries = ActiveCompany::runAs((int) $pair->company_id,
                fn () => $lotSvc->remainingLotsFor((int) $pair->stock_item_id, null, $asOf));

            $itemName = StockItem::withoutGlobalScope('company')->whereKey($pair->stock_item_id)->value('name');

            foreach ($entries as $e) {
                $lot = $e['lot'];
                if (! in_array((int) $lot->source_company_id, $members, true)) {
                    continue; // lot from a company outside THIS group — not ours to eliminate
                }
                if ($lot->source_cost_paise === null) {
                    $value = (int) round($lot->received_rate_paise * $e['remaining']);
                    $unmatchedValue += $value;
                    $unmatched[] = [
                        'item' => $itemName,
                        'company' => $companyNames[$pair->company_id] ?? ('#'.$pair->company_id),
                        'source_company' => $companyNames[(int) $lot->source_company_id] ?? ('#'.$lot->source_company_id),
                        'remaining' => $e['remaining'],
                        'value_at_receipt' => $value,
                        'receiving_voucher_id' => (int) $lot->voucher_id,
                    ];

                    continue;
                }

                $elim = (int) round(($lot->received_rate_paise - $lot->source_cost_paise) * $e['remaining']);
                $total += $elim;
                $items[] = [
                    'item' => $itemName,
                    'company' => $companyNames[$pair->company_id] ?? ('#'.$pair->company_id),
                    'source_company' => $companyNames[(int) $lot->source_company_id] ?? ('#'.$lot->source_company_id),
                    'remaining' => $e['remaining'],
                    'received_rate_paise' => (int) $lot->received_rate_paise,
                    'source_cost_paise' => (int) $lot->source_cost_paise,
                    'elimination' => $elim,
                ];
            }
        }

        return ['total' => $total, 'items' => $items, 'unmatched' => $unmatched, 'unmatched_value' => $unmatchedValue];
    }

    /**
     * Untagged inter-group vouchers — entries touching a ledger LINKED to a
     * groupmate but carrying no 12B tag: pre-12B history, or forex revaluation
     * journals (tag-exempt by design). Surfaced, never silently double-counted.
     */
    public function untaggedInterCompanyVouchers(CompanyGroup $group, ?Carbon $from, Carbon $to): array
    {
        $members = $this->memberIds($group);

        return VoucherEntry::withoutGlobalScope('company')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->join('ledgers', 'ledgers.id', '=', 'voucher_entries.ledger_id')
            ->leftJoin('voucher_intercompany_tags as ict', 'ict.voucher_id', '=', 'vouchers.id')
            ->whereIn('voucher_entries.company_id', $members)
            ->whereIn('ledgers.linked_company_id', $members)
            // UNACCOUNTED = no tag at all (pre-group history, tag-exempt revaluation
            // journals) OR a tag whose counterparty is no longer a CURRENT member
            // (the 12B stale-tag case) — such a voucher neither eliminates nor may
            // it silently double-count, so it surfaces here.
            ->where(function ($q) use ($members) {
                $q->whereNull('ict.id')->orWhereNotIn('ict.counterparty_company_id', $members);
            })
            ->when($from !== null, fn ($q) => $q->where('vouchers.date', '>=', $from->toDateString()))
            ->where('vouchers.date', '<=', $to->toDateString())
            ->whereNull('vouchers.scenario_id') // REAL BOOKS ONLY — scenarios never widen consolidation
            ->groupBy('vouchers.id', 'vouchers.company_id', 'vouchers.type', 'vouchers.number', 'vouchers.date')
            ->select('vouchers.id', 'vouchers.company_id', 'vouchers.type', 'vouchers.number', 'vouchers.date')
            ->selectRaw('SUM(voucher_entries.amount) AS touched')
            ->orderBy('vouchers.date')
            ->get()
            ->map(fn ($v) => [
                'voucher_id' => (int) $v->id,
                'company' => Company::find($v->company_id)?->name,
                'type' => $v->type,
                'number' => (int) $v->number,
                'date' => (string) $v->date,
                'amount_paise' => (int) round(((float) $v->touched) * 100),
            ])->all();
    }

    /**
     * THE FOUNDATION — per-member adjusted ledger rows: ledgerBalances() minus
     * the tagged contributions, each row carrying its account-group name, root
     * name, nature, and company. Everything else derives from this.
     */
    public function adjustedLedgerRows(CompanyGroup $group, Carbon $from, Carbon $to, array $elimsByLedger): array
    {
        // Keyed by group+period; the elim set is a deterministic function of them.
        $key = 'alr:'.$group->id.':'.$from->toDateString().':'.$to->toDateString();

        return $this->once($key, fn () => $this->computeAdjustedLedgerRows($group, $from, $to, $elimsByLedger));
    }

    private function computeAdjustedLedgerRows(CompanyGroup $group, Carbon $from, Carbon $to, array $elimsByLedger): array
    {
        $rows = [];

        foreach ($this->memberIds($group) as $memberId) {
            $memberRows = ActiveCompany::runAs($memberId, function () use ($memberId, $from, $to, $elimsByLedger) {
                $bs = new BalanceService();
                $lb = $bs->ledgerBalances($from, $to);
                $ledgers = Ledger::with('group')->get()->keyBy('id');

                $out = [];
                foreach ($lb as $L) {
                    $ledger = $ledgers->get($L['id']);
                    $elim = $elimsByLedger[$memberId.':'.$L['id']] ?? 0;
                    $out[] = [
                        'company_id' => $memberId,
                        'ledger_id' => $L['id'],
                        'ledger' => $L['name'],
                        'group_name' => $ledger?->group?->name ?? '—',
                        'root_name' => $this->rootNameFor($ledger),
                        'nature' => $this->natureFor($ledger),
                        'opening' => $L['opening'],
                        'net' => $L['net'],
                        'closing' => $L['closing'],
                        'elimination' => $elim,
                        'adjusted_closing' => $L['closing'] - $elim,
                        'adjusted_net' => $L['net'] - $elim, // P&L basis: tags only exist on entries (openings never tagged)
                    ];
                }

                return $out;
            });

            $rows = array_merge($rows, $memberRows);
        }

        return $rows;
    }

    /** GROUP TRIAL BALANCE — adjusted rows rolled up by account-group name. */
    public function groupTrialBalance(CompanyGroup $group, Carbon $from, Carbon $to): array
    {
        if ($err = $this->currencyMismatch($group)) {
            return ['error' => $err];
        }

        $elims = $this->completeEliminations($group, null, $to); // closings: since book start
        $rows = $this->adjustedLedgerRows($group, $from, $to, $elims['byLedger']);

        $sections = [];
        $totalDr = 0;
        $totalCr = 0;
        foreach ($rows as $r) {
            if ($r['adjusted_closing'] === 0 && $r['closing'] === 0) {
                continue;
            }
            $sections[$r['group_name']]['name'] = $r['group_name'];
            $sections[$r['group_name']]['rows'][] = $r;
            $sections[$r['group_name']]['closing'] = ($sections[$r['group_name']]['closing'] ?? 0) + $r['adjusted_closing'];
            if ($r['adjusted_closing'] > 0) {
                $totalDr += $r['adjusted_closing'];
            } elseif ($r['adjusted_closing'] < 0) {
                $totalCr += -$r['adjusted_closing'];
            }
        }
        ksort($sections);

        return [
            'sections' => array_values($sections),
            'total_dr' => $totalDr,
            'total_cr' => $totalCr,
            'balanced' => $totalDr === $totalCr,
            'eliminations' => $elims,
        ];
    }

    /** GROUP P&L — 6D's exact math over adjusted rows + unrealised stock terms. */
    public function groupProfitAndLoss(CompanyGroup $group, Carbon $from, Carbon $to): array
    {
        if ($err = $this->currencyMismatch($group)) {
            return ['error' => $err];
        }

        $members = $this->memberIds($group);
        // Aggregation basis: ADJUSTED CLOSINGS with since-book-start eliminations —
        // the exact 6D per-company convention, so Σ member nets ties structurally.
        // The period-filtered eliminations are computed separately for the display
        // panel (the spec's P&L elimination view); on books that live entirely
        // inside the period the two are identical.
        $elims = $this->completeEliminations($group, null, $to);
        $elimsPeriod = $this->completeEliminations($group, $from, $to);
        $rows = $this->adjustedLedgerRows($group, $from, $to, $elims['byLedger']);

        $tradingIncome = 0;
        $tradingExpense = 0;
        $indirectIncome = 0;
        $indirectExpense = 0;
        foreach ($rows as $r) {
            if ($r['nature'] === 'Income') {
                $isTrading = in_array($r['root_name'], BalanceService::TRADING_ROOTS, true);
                $isTrading ? $tradingIncome += -$r['adjusted_closing'] : $indirectIncome += -$r['adjusted_closing'];
            } elseif ($r['nature'] === 'Expenses') {
                $isTrading = in_array($r['root_name'], BalanceService::TRADING_ROOTS, true);
                $isTrading ? $tradingExpense += $r['adjusted_closing'] : $indirectExpense += $r['adjusted_closing'];
            }
        }

        // Stock terms: Σ member stock, with the unrealised markup removed at BOTH
        // boundaries — the P&L therefore recognises only the CHANGE in unrealised
        // profit over the period (the consolidation-correct movement).
        $unrealisedAtFrom = $this->unrealisedProfitElimination($group, $from->copy()->subDay());
        $unrealisedAtTo = $this->unrealisedProfitElimination($group, $to);
        $openingStock = 0;
        $closingStock = 0;
        $memberNets = [];
        foreach ($members as $memberId) {
            [$o, $c, $net, $name] = ActiveCompany::runAs($memberId, function () use ($from, $to) {
                $stock = app(StockService::class);
                $pl = (new BalanceService())->profitAndLoss($from, $to);

                return [
                    (int) round($stock->totalOpeningValue() * 100),
                    (int) round($stock->totalClosingValue($to) * 100),
                    $pl['net'],
                    activeCompany()->name,
                ];
            });
            $openingStock += $o;
            $closingStock += $c;
            $memberNets[] = ['company_id' => $memberId, 'company' => $name, 'net' => $net];
        }
        $groupOpeningStock = $openingStock - $unrealisedAtFrom['total'];
        $groupClosingStock = $closingStock - $unrealisedAtTo['total'];

        $grossProfit = ($tradingIncome + $groupClosingStock) - ($tradingExpense + $groupOpeningStock);
        $net = $grossProfit + $indirectIncome - $indirectExpense;

        // THE TIE CHECK — group net must equal Σ individual nets − Δunrealised
        // (complete eliminations cancel on the profit line by double entry).
        $sumNets = (int) array_sum(array_column($memberNets, 'net'));
        $expectedNet = $sumNets - ($unrealisedAtTo['total'] - $unrealisedAtFrom['total']);

        return [
            'trading_income' => $tradingIncome,
            'trading_expense' => $tradingExpense,
            'indirect_income' => $indirectIncome,
            'indirect_expense' => $indirectExpense,
            'opening_stock' => $groupOpeningStock,
            'closing_stock' => $groupClosingStock,
            'gross_profit' => $grossProfit,
            'net' => $net,
            'is_profit' => $net >= 0,
            'member_nets' => $memberNets,
            'tie_check' => $net === $expectedNet,
            'tie_expected' => $expectedNet,
            'eliminations' => $elimsPeriod, // the spec's period view (panel/display)
            'unrealised' => $unrealisedAtTo,
            'unrealised_at_from' => $unrealisedAtFrom['total'],
            'rows' => $rows,
        ];
    }

    /** GROUP BALANCE SHEET — nature rollup + stock injection + net + equity adjustment. */
    public function groupBalanceSheet(CompanyGroup $group, Carbon $from, Carbon $to): array
    {
        if ($err = $this->currencyMismatch($group)) {
            return ['error' => $err];
        }

        $elims = $this->completeEliminations($group, null, $to); // closings basis
        $rows = $this->adjustedLedgerRows($group, $from, $to, $elims['byLedger']);
        $pl = $this->groupProfitAndLoss($group, $from, $to);

        $assetSections = [];
        $liabilitySections = [];
        $totalAssetsLedger = 0;
        $totalLiabilities = 0;
        foreach ($rows as $r) {
            if ($r['nature'] === 'Assets') {
                $totalAssetsLedger += $r['adjusted_closing'];
                $this->collect($assetSections, $r);
            } elseif ($r['nature'] === 'Liabilities') {
                $totalLiabilities += -$r['adjusted_closing'];
                $this->collect($liabilitySections, $r);
            }
        }
        ksort($assetSections);
        ksort($liabilitySections);

        $groupClosingStock = $pl['closing_stock']; // already unrealised-adjusted
        $net = $pl['net'];
        // The PRIOR-period unrealised portion cannot flow through this period's
        // P&L — it lands on the explicit equity adjustment line (no seeded
        // Retained Earnings ledger exists; this is a REPORT line, never a posting).
        $consolidationAdjustment = -$pl['unrealised_at_from'];

        $totalAssets = $totalAssetsLedger + $groupClosingStock;
        $liabSide = $totalLiabilities + max($net, 0) + $consolidationAdjustment;
        $assetSide = $totalAssets + max(-$net, 0);

        $diff = $assetSide - $liabSide;

        return [
            'asset_sections' => array_values($assetSections),
            'liability_sections' => array_values($liabilitySections),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'closing_stock' => $groupClosingStock,
            'unrealised_elimination' => $pl['unrealised']['total'],
            'consolidation_adjustment' => $consolidationAdjustment,
            'net' => $net,
            'is_profit' => $net >= 0,
            'liability_total' => $liabSide + max($diff, 0),
            'asset_total' => $assetSide + max(-$diff, 0),
            'difference' => $diff, // 0 when every invariant holds; surfaced when not
            'balanced' => $diff === 0,
            'eliminations' => $elims,
            'unrealised' => $pl['unrealised'],
        ];
    }

    /** The audit-trail panel shared by all three screens. */
    public function adjustmentsPanel(CompanyGroup $group, Carbon $from, Carbon $to): array
    {
        $elims = $this->completeEliminations($group, $from, $to);
        $unrealised = $this->unrealisedProfitElimination($group, $to);
        $untagged = $this->untaggedInterCompanyVouchers($group, null, $to);

        return [
            'complete' => $elims,
            'unrealised' => $unrealised,
            'untagged' => $untagged,
        ];
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function collect(array &$sections, array $r): void
    {
        if ($r['adjusted_closing'] === 0 && $r['closing'] === 0) {
            return;
        }
        $sections[$r['group_name']]['name'] = $r['group_name'];
        $sections[$r['group_name']]['rows'][] = $r;
        $sections[$r['group_name']]['closing'] = ($sections[$r['group_name']]['closing'] ?? 0) + $r['adjusted_closing'];
    }

    /** The ledger's ROOT account-group name (walks parents; ≤12 levels). */
    private function rootNameFor(?Ledger $ledger): string
    {
        $g = $ledger?->group;
        $guard = 0;
        while ($g && $g->parent_id && $guard++ < 12) {
            $parent = \App\Models\AccountGroup::withoutGlobalScope('company')->find($g->parent_id);
            if (! $parent) {
                break;
            }
            $g = $parent;
        }

        return $g?->name ?? '—';
    }

    /** The ledger's nature via its root group (Assets/Liabilities/Income/Expenses). */
    private function natureFor(?Ledger $ledger): string
    {
        $g = $ledger?->group;
        $guard = 0;
        while ($g && ! $g->nature && $g->parent_id && $guard++ < 12) {
            $g = \App\Models\AccountGroup::withoutGlobalScope('company')->find($g->parent_id);
        }

        return $g?->nature ?? '—';
    }
}
