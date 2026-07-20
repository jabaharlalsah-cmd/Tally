<?php

namespace App\Services\Gst;

use App\Models\CompanyFeature;
use App\Models\Voucher;
use App\Services\GstService;
use App\Support\ScenarioContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Phase 9A — GSTR-1 (outward supplies) and GSTR-3B (summary) JSON export.
 *
 * Built field-for-field against the schema derived from the Government's own
 * **Returns Offline Tool v3.2.4** and **GSTR-3B Excel Utility V5.8** — see
 * `_docs/gstn-schemas/gstr1-schema.json` and `gstr3b-schema.json`. Nothing here is
 * from recollection: a returns JSON with one wrong field is rejected by the portal.
 *
 * Read-only. It touches Voucher / VoucherEntry / Ledger / StockEntry / CompanyFeature
 * and mutates none of them.
 *
 * WHAT FLOWS WHERE
 *   • Sales to a party WITH a GSTIN                          → b2b   (invoice level)
 *   • Sales to a party WITHOUT a GSTIN, inter-state, value    → b2cl  (invoice level)
 *     strictly above the period's threshold
 *   • All other sales to unregistered parties                → b2cs  (rate/POS summary)
 *   • Credit Notes to a registered party                     → cdnr
 *   • Credit Notes to an unregistered party that would have  → cdnur (typ B2CL)
 *     been a B2CL supply
 *   • Credit Notes to an unregistered party otherwise        → netted INTO b2cs (−)
 *   • Item lines of the above                                → hsn.hsn_b2b / hsn.hsn_b2c
 *   • Document number ranges issued                          → doc_issue
 *
 * WHY A DEBIT NOTE IS NOT IN GSTR-1
 *   GSTR-1 reports OUTWARD supplies. ZeroBook's `credit_note` is customer-side (a
 *   sales return; it reverses OUTPUT tax) so it adjusts our outward supplies and is
 *   reported in cdnr/cdnur. ZeroBook's `debit_note` is supplier-side (a purchase
 *   return; it reverses INPUT tax) — that is an inward adjustment which belongs in the
 *   *supplier's* GSTR-1, not ours. It is still fully accounted for: it reduces our
 *   input tax credit, which GstService::summary() nets automatically, so it lands in
 *   GSTR-3B's `itc_elg`. It is also listed in `doc_issue` (a document we issued).
 *
 * WHAT NEVER APPEARS
 *   Phase 8B inventory-workflow vouchers (Sales/Purchase Order, Delivery/Receipt Note,
 *   Rejections) carry no tax and no ledger entries at all, so they cannot reach either
 *   return — {@see self::TAX_VOUCHER_TYPES}. The computed Profit & Loss A/c ledger is
 *   never postable and is skipped defensively.
 */
class GstReturnService
{
    /** The tool stamps every generated file with its own version + a `hash` placeholder. */
    public const VERSION = 'GST3.2.4';

    public const HASH = 'hash';

    /** Only these four voucher types carry tax. Workflow/stock vouchers are excluded by construction. */
    public const TAX_VOUCHER_TYPES = ['sales', 'purchase', 'credit_note', 'debit_note'];

    /** GSTR-1 reports outward supplies; only a customer-side Credit Note adjusts them. */
    public const OUTWARD_NOTE_TYPES = ['credit_note'];

    public function __construct(private GstService $gst) {}

    // ── company identity ────────────────────────────────────────────────────

    /** The filer's GSTIN. Without it there is no return to file. */
    public function companyGstin(): string
    {
        $gstin = trim((string) activeCompany()?->gstin);
        if ($gstin === '') {
            throw new RuntimeException('Company GSTIN not configured — set it in F11 Features before generating returns');
        }

        return $gstin;
    }

    // ── GSTR-1 ──────────────────────────────────────────────────────────────

    /**
     * The complete GSTR-1 document for a `MMYYYY` period, ready for json_encode.
     * Every section the tool emits is present (empty where we have nothing to report),
     * because the portal's importer expects the full envelope.
     */
    public function gstr1(string $period): array
    {
        GstnFormat::assertPeriod($period);
        [$from, $to] = GstnFormat::periodRange($period);
        if (\App\Models\Voucher::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNotNull('scenario_id')->exists()) {
            throw new \RuntimeException('Scenario vouchers exist in this period. Return files must use actuals only.');
        }
        $gstin = $this->companyGstin();
        $docs = $this->documents($period);

        return [
            'gstin' => $gstin,
            'fp' => $period,
            'version' => self::VERSION,
            'hash' => self::HASH,
            'b2b' => $this->buildB2b($docs),
            'b2ba' => [],
            'b2cl' => $this->buildB2cl($docs, $period),
            'b2cla' => [],
            'b2cs' => $this->buildB2cs($docs, $period),
            'b2csa' => [],
            // Nil-rated / exempt / non-GST outward supplies are not tracked by ZeroBook
            // (no ledger flag distinguishes them), so this stays empty — reported, skipped.
            'nil' => ['inv' => []],
            // Exports are out of 9A's scope (the tenant would need exporter registration).
            'exp' => [],
            'expa' => [],
            'hsnSac' => [],
            'cdnra' => [],
            'at' => [],
            'ata' => [],
            'cdnr' => $this->buildCdnr($docs),
            'cdnur' => $this->buildCdnur($docs, $period),
            'cdnura' => [],
            'atadj' => [],
            'atadja' => [],
            'doc_issue' => $this->buildDocs($period),
            'hsn' => $this->buildHsn($docs, $period),
            'supeco' => ['clttx' => [], 'paytx' => []],
            'supecoa' => ['clttxa' => [], 'paytxa' => []],
            'ecom' => ['b2b' => [], 'b2c' => [], 'urp2b' => [], 'urp2c' => []],
            'ecoma' => ['b2ba' => [], 'b2ca' => [], 'urp2ba' => [], 'urp2ca' => []],
        ];
    }

    /** B2B — invoice-level detail of sales to GSTIN-holding parties, grouped by recipient. */
    public function buildB2b(Collection $docs): array
    {
        $byCtin = [];
        foreach ($docs as $d) {
            if ($d['type'] !== 'sales' || ! $d['registered']) {
                continue;
            }
            $byCtin[$d['ctin']] ??= ['ctin' => $d['ctin'], 'cname' => $d['party']->name, 'inv' => []];
            $byCtin[$d['ctin']]['inv'][] = [
                'inum' => $d['display'],
                'idt' => GstnFormat::formatDate($d['date']),
                'val' => $d['val'],
                'pos' => $d['pos'],
                'rchrg' => 'N',
                'inv_typ' => 'R',
                'itms' => $this->itms($d),
            ];
        }

        return array_values($byCtin);
    }

    /** B2CL — unregistered + inter-state + value strictly above the period's threshold. */
    public function buildB2cl(Collection $docs, string $period): array
    {
        $byPos = [];
        foreach ($docs as $d) {
            if ($d['type'] !== 'sales' || ! $this->qualifiesAsLarge($d, $period)) {
                continue;
            }
            $byPos[$d['pos']] ??= ['pos' => $d['pos'], 'inv' => []];
            $byPos[$d['pos']]['inv'][] = [
                'inum' => $d['display'],
                'idt' => GstnFormat::formatDate($d['date']),
                'val' => $d['val'],
                // B2CL is inter-state by definition, so the line carries IGST only.
                'itms' => $this->itms($d, true),
            ];
        }

        return array_values($byPos);
    }

    /**
     * B2CS — everything else sold to unregistered parties, summarised by
     * (supply type, place of supply, rate). Credit Notes to unregistered parties that
     * are too small to be reported in CDNUR are netted off here, which is exactly how
     * the portal expects a B2C return to be reduced.
     */
    public function buildB2cs(Collection $docs, string $period): array
    {
        $acc = [];
        foreach ($docs as $d) {
            if ($d['registered']) {
                continue;
            }
            $sign = 0;
            if ($d['type'] === 'sales' && ! $this->qualifiesAsLarge($d, $period)) {
                $sign = 1;
            } elseif ($this->isOutwardNote($d) && ! $this->qualifiesAsLarge($d, $period)) {
                $sign = -1;
            }
            if ($sign === 0) {
                continue;
            }

            $splyTy = $d['intra'] ? 'INTRA' : 'INTER';
            foreach ($d['slabs'] as $s) {
                $key = $splyTy.'|'.$d['pos'].'|'.$s['rate'];
                $acc[$key] ??= [
                    'sply_ty' => $splyTy, 'pos' => $d['pos'], 'rt' => $s['rate'],
                    'txval_p' => 0, 'iamt_p' => 0, 'camt_p' => 0, 'samt_p' => 0, 'csamt_p' => 0,
                ];
                $acc[$key]['txval_p'] += $sign * $s['txval_p'];
                $acc[$key]['iamt_p'] += $sign * $s['iamt_p'];
                $acc[$key]['camt_p'] += $sign * $s['camt_p'];
                $acc[$key]['samt_p'] += $sign * $s['samt_p'];
            }
        }

        $rows = [];
        foreach ($acc as $r) {
            // A bucket fully reversed by credit notes contributes nothing to the return.
            if ($r['txval_p'] === 0 && $r['iamt_p'] === 0 && $r['camt_p'] === 0 && $r['samt_p'] === 0) {
                continue;
            }
            $row = [
                'sply_ty' => $r['sply_ty'],
                'typ' => 'OE', // Other than E-commerce; ZeroBook has no ECO supplies.
                'pos' => $r['pos'],
                'rt' => GstnFormat::formatRate($r['rt']),
                'txval' => GstnFormat::paiseToMoney($r['txval_p']),
            ];
            if ($r['sply_ty'] === 'INTER') {
                $row['iamt'] = GstnFormat::paiseToMoney($r['iamt_p']);
            } else {
                $row['camt'] = GstnFormat::paiseToMoney($r['camt_p']);
                $row['samt'] = GstnFormat::paiseToMoney($r['samt_p']);
            }
            $row['csamt'] = GstnFormat::paiseToMoney($r['csamt_p']);
            $rows[] = $row;
        }

        return $rows;
    }

    /** CDNR — Credit Notes issued to registered parties, grouped by recipient GSTIN. */
    public function buildCdnr(Collection $docs): array
    {
        $byCtin = [];
        foreach ($docs as $d) {
            if (! $this->isOutwardNote($d) || ! $d['registered']) {
                continue;
            }
            // No `cname` here: the tool strips it from cdnr before upload.
            $byCtin[$d['ctin']] ??= ['ctin' => $d['ctin'], 'nt' => []];
            $byCtin[$d['ctin']]['nt'][] = [
                'ntty' => 'C',
                'nt_num' => $d['display'],
                'nt_dt' => GstnFormat::formatDate($d['date']),
                'val' => $d['val'],
                'pos' => $d['pos'],
                'rchrg' => 'N',
                'inv_typ' => 'R',
                // CDN is delinked from the invoice (tool release 2.4) — no inum/idt.
                'itms' => $this->itms($d),
            ];
        }

        return array_values($byCtin);
    }

    /** CDNUR — Credit Notes to unregistered parties against what would be a B2CL supply. */
    public function buildCdnur(Collection $docs, string $period): array
    {
        $rows = [];
        foreach ($docs as $d) {
            if (! $this->isOutwardNote($d) || ! $this->qualifiesAsLarge($d, $period)) {
                continue;
            }
            $rows[] = [
                'typ' => 'B2CL',
                'ntty' => 'C',
                'nt_num' => $d['display'],
                'nt_dt' => GstnFormat::formatDate($d['date']),
                'pos' => $d['pos'],
                'val' => $d['val'],
                // A cdnur itm_det carries EXACTLY txval/rt/iamt/csamt — never camt/samt.
                'itms' => $this->itms($d, true),
            ];
        }

        return $rows;
    }

    /**
     * HSN summary of outward supplies. From the 052025 return period the section is
     * bifurcated into `hsn_b2b` + `hsn_b2c`; before it, a single `data[]` array.
     * Only item-invoice lines carry an HSN/SAC, so accounting-only invoices cannot be
     * summarised here (they have no item lines).
     */
    public function buildHsn(Collection $docs, string $period): array
    {
        $acc = ['b2b' => [], 'b2c' => []];

        foreach ($docs as $d) {
            $sign = $d['type'] === 'sales' ? 1 : ($this->isOutwardNote($d) ? -1 : 0);
            if ($sign === 0) {
                continue;
            }
            $bucket = $d['registered'] ? 'b2b' : 'b2c';
            foreach ($d['items'] as $it) {
                $hsn = trim((string) ($it['hsn'] ?? ''));
                if ($hsn === '') {
                    continue; // no HSN on the master → cannot be reported
                }
                $rate = GstnFormat::formatRate($it['rate']);
                $baseP = (int) round($it['amount'] * 100);
                // Same paise formula the posting engine used, so the summary ties out.
                if ($d['intra']) {
                    $half = (int) round($baseP * $rate / 200);
                    [$iP, $cP, $sP] = [0, $half, $half];
                } else {
                    [$iP, $cP, $sP] = [(int) round($baseP * $rate / 100), 0, 0];
                }

                $key = $hsn.'|'.$it['uqc'].'|'.$rate;
                $acc[$bucket][$key] ??= [
                    'hsn_sc' => $hsn, 'desc' => (string) $it['desc'], 'uqc' => $it['uqc'], 'rt' => $rate,
                    'qty' => 0.0, 'txval_p' => 0, 'iamt_p' => 0, 'camt_p' => 0, 'samt_p' => 0,
                ];
                $acc[$bucket][$key]['qty'] += $sign * $it['qty'];
                $acc[$bucket][$key]['txval_p'] += $sign * $baseP;
                $acc[$bucket][$key]['iamt_p'] += $sign * $iP;
                $acc[$bucket][$key]['camt_p'] += $sign * $cP;
                $acc[$bucket][$key]['samt_p'] += $sign * $sP;
            }
        }

        $rows = function (array $bucket): array {
            $out = [];
            $n = 1;
            foreach ($bucket as $r) {
                $txval = GstnFormat::paiseToMoney($r['txval_p']);
                $iamt = GstnFormat::paiseToMoney($r['iamt_p']);
                $camt = GstnFormat::paiseToMoney($r['camt_p']);
                $samt = GstnFormat::paiseToMoney($r['samt_p']);
                $out[] = [
                    'num' => $n++,
                    'hsn_sc' => $r['hsn_sc'],
                    'desc' => mb_substr($r['desc'], 0, 30),
                    'uqc' => $r['uqc'],
                    'qty' => GstnFormat::formatQty($r['qty']),
                    'rt' => $r['rt'],
                    'txval' => $txval,
                    'iamt' => $iamt,
                    'camt' => $camt,
                    'samt' => $samt,
                    'csamt' => 0.0,
                    'val' => GstnFormat::formatMoney($txval + $iamt + $camt + $samt),
                ];
            }

            return $out;
        };

        if (GstnFormat::hsnIsBifurcated($period)) {
            return ['hsn_b2b' => $rows($acc['b2b']), 'hsn_b2c' => $rows($acc['b2c'])];
        }

        // Legacy single-list form: merge the two buckets on the same key.
        $merged = [];
        foreach (['b2b', 'b2c'] as $b) {
            foreach ($acc[$b] as $key => $r) {
                if (! isset($merged[$key])) {
                    $merged[$key] = $r;

                    continue;
                }
                foreach (['qty', 'txval_p', 'iamt_p', 'camt_p', 'samt_p'] as $f) {
                    $merged[$key][$f] += $r[$f];
                }
            }
        }

        return ['data' => $rows($merged)];
    }

    /**
     * DOC_ISSUE (Table 13) — the document number ranges issued in the period, per
     * nature of document. A gap between the first and last number used is a document
     * that was cancelled, which is exactly what `cancel` reports.
     */
    public function buildDocs(string $period): array
    {
        [$from, $to] = GstnFormat::periodRange($period);

        // doc_num codes from the tool's Nature-of-Document list (1-based).
        $natures = [
            1 => 'sales',        // Invoices for outward supply
            4 => 'debit_note',   // Debit Note
            5 => 'credit_note',  // Credit Note
        ];

        $det = [];
        foreach ($natures as $docNum => $type) {
            $numbers = Voucher::where('type', $type)
                ->whereNull('vouchers.scenario_id')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->pluck('number')->map(fn ($n) => (int) $n)->sort()->values();

            if ($numbers->isEmpty()) {
                continue;
            }
            $min = $numbers->first();
            $max = $numbers->last();
            $issued = $numbers->count();
            $total = $max - $min + 1;

            $det[] = [
                'doc_num' => $docNum,
                'docs' => [[
                    'num' => 1,
                    'from' => $this->documentLabel($type, $min),
                    'to' => $this->documentLabel($type, $max),
                    'totnum' => $total,
                    'cancel' => $total - $issued,
                    'net_issue' => $issued,
                ]],
            ];
        }

        return ['doc_det' => $det];
    }

    // ── GSTR-3B ─────────────────────────────────────────────────────────────

    /**
     * The complete GSTR-3B document. This is a thin, faithful wrapper over the
     * existing `GstService::summary()` aggregation — the tax math is not re-derived,
     * only mapped onto GSTN's field names.
     *
     * NOTE: the official GSTR-3B Excel Utility emits **no tax-payment section** — there
     * is no `tx_pmt` (nor `tax_pmt`) key anywhere in the file it generates; payment /
     * offset happens on the portal against the electronic ledgers. See
     * `_docs/gstn-schemas/gstr3b-schema.json` → `_absent`. The net payable is still
     * surfaced by {@see self::preview()} so a CA can eyeball it before filing.
     */
    public function gstr3b(string $period): array
    {
        GstnFormat::assertPeriod($period);
        $gstin = $this->companyGstin();
        [$from, $to] = GstnFormat::periodRange($period);

        if (\App\Models\Voucher::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->whereNotNull('scenario_id')->exists()) {
            throw new \RuntimeException('Scenario vouchers exist in this period. Return files must use actuals only.');
        }

        return ScenarioContext::runWith([], function () use ($period, $gstin, $from, $to) {
            $s = $this->gst->summary($from, $to);
            $p = fn (int $paise) => GstnFormat::paiseToMoney($paise);
            $zeroTax = ['txval' => 0.0, 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0];
            $zeroAmts = fn (string $ty) => ['ty' => $ty, 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0];

            return [
                'gstin' => $gstin,
                'ret_period' => $period,
                'sup_details' => [
                    // 3.1(a) Outward taxable supplies (other than zero-rated, nil, exempt).
                    // taxable_sales is already NET of Sales Return (it lives in the Sales group).
                    'osup_det' => [
                        'txval' => $p($s['taxable_sales']),
                        'iamt' => $p($s['output']['integrated']),
                        'camt' => $p($s['output']['central']),
                        'samt' => $p($s['output']['state']),
                        'csamt' => 0.0,
                    ],
                    // ZeroBook does not classify zero-rated / nil / exempt / non-GST supplies
                    // or reverse-charge inward supplies, so these stay at zero.
                    'osup_zero' => $zeroTax,
                    'osup_nil_exmp' => $zeroTax,
                    'isup_rev' => $zeroTax,
                    'osup_nongst' => $zeroTax,
                ],
                // 3.1.1 e-commerce operator / Sec 9(5) supplies — none.
                'eco_dtls' => [
                    'eco_sup' => $zeroTax,
                    'eco_reg_sup' => ['txval' => 0.0],
                ],
                'itc_elg' => [
                    // 4(A) ITC available. Every credit ZeroBook tracks is ordinary input tax
                    // on domestic purchases → the "All other ITC" (OTH) row.
                    'itc_avl' => [
                        $zeroAmts('IMPG'),
                        $zeroAmts('IMPS'),
                        $zeroAmts('ISRC'),
                        $zeroAmts('ISD'),
                        [
                            'ty' => 'OTH',
                            'iamt' => $p($s['input']['integrated']),
                            'camt' => $p($s['input']['central']),
                            'samt' => $p($s['input']['state']),
                            'csamt' => 0.0,
                        ],
                    ],
                    // 4(B) ITC reversed — a Debit Note (purchase return) already reduces the
                    // input-tax ledger balance, so it is netted into 4(A), not reported here.
                    'itc_rev' => [$zeroAmts('RUL'), $zeroAmts('OTH')],
                    // 4(C) Net ITC available = 4(A) − 4(B).
                    'itc_net' => [
                        'iamt' => $p($s['input']['integrated']),
                        'camt' => $p($s['input']['central']),
                        'samt' => $p($s['input']['state']),
                        'csamt' => 0.0,
                    ],
                    // 4(D) Ineligible ITC — none tracked.
                    'itc_inelg' => [$zeroAmts('RUL'), $zeroAmts('OTH')],
                ],
                // 5. Values of exempt / nil-rated / non-GST inward supplies — none tracked.
                'inward_sup' => [
                    'isup_details' => [
                        ['ty' => 'GST', 'inter' => 0.0, 'intra' => 0.0],
                        ['ty' => 'NONGST', 'inter' => 0.0, 'intra' => 0.0],
                    ],
                ],
                // 5.1 Interest & late fee — user-entered on the portal.
                'intr_ltfee' => [
                    'intr_details' => ['iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0],
                    'ltfee_details' => ['camt' => 0.0, 'samt' => 0.0],
                ],
                // 3.2 Of the supplies in 3.1(a), inter-state supplies to unregistered persons.
                'inter_sup' => [
                    'unreg_details' => $this->interStateUnregistered($period),
                    'comp_details' => [],
                    'uin_details' => [],
                ],
            ];
        });
    }

    /** Table 3.2 — inter-state supplies to unregistered persons, by place of supply. */
    private function interStateUnregistered(string $period): array
    {
        $acc = [];
        foreach ($this->documents($period) as $d) {
            if ($d['registered'] || $d['intra']) {
                continue;
            }
            $sign = $d['type'] === 'sales' ? 1 : ($this->isOutwardNote($d) ? -1 : 0);
            if ($sign === 0) {
                continue;
            }
            $acc[$d['pos']] ??= ['pos' => $d['pos'], 'txval_p' => 0, 'iamt_p' => 0];
            foreach ($d['slabs'] as $s) {
                $acc[$d['pos']]['txval_p'] += $sign * $s['txval_p'];
                $acc[$d['pos']]['iamt_p'] += $sign * $s['iamt_p'];
            }
        }

        $rows = [];
        foreach ($acc as $r) {
            if ($r['txval_p'] === 0 && $r['iamt_p'] === 0) {
                continue;
            }
            $rows[] = [
                'pos' => $r['pos'],
                'txval' => GstnFormat::paiseToMoney($r['txval_p']),
                'iamt' => GstnFormat::paiseToMoney($r['iamt_p']),
            ];
        }

        return $rows;
    }

    // ── preview (what the CA eyeballs before filing) ────────────────────────

    /**
     * A human summary of the return, computed **from the generated JSON itself** — so
     * the preview can never drift from the file that gets uploaded.
     */
    public function preview(string $period): array
    {
        $g1 = $this->gstr1($period);
        $g3 = $this->gstr3b($period);

        $sumItms = function (array $itms): array {
            $tx = 0.0;
            $tax = 0.0;
            foreach ($itms as $i) {
                $d = $i['itm_det'];
                $tx += $d['txval'];
                $tax += ($d['iamt'] ?? 0) + ($d['camt'] ?? 0) + ($d['samt'] ?? 0) + ($d['csamt'] ?? 0);
            }

            return [$tx, $tax];
        };

        $b2bInv = 0;
        $tx = 0.0;
        $tax = 0.0;
        foreach ($g1['b2b'] as $g) {
            foreach ($g['inv'] as $inv) {
                $b2bInv++;
                [$a, $b] = $sumItms($inv['itms']);
                $tx += $a;
                $tax += $b;
            }
        }
        $b2clInv = 0;
        foreach ($g1['b2cl'] as $g) {
            foreach ($g['inv'] as $inv) {
                $b2clInv++;
                [$a, $b] = $sumItms($inv['itms']);
                $tx += $a;
                $tax += $b;
            }
        }
        foreach ($g1['b2cs'] as $r) {
            $tx += $r['txval'];
            $tax += ($r['iamt'] ?? 0) + ($r['camt'] ?? 0) + ($r['samt'] ?? 0) + ($r['csamt'] ?? 0);
        }
        $cdnrNotes = 0;
        foreach ($g1['cdnr'] as $g) {
            foreach ($g['nt'] as $nt) {
                $cdnrNotes++;
                [$a, $b] = $sumItms($nt['itms']);
                $tx -= $a;
                $tax -= $b;
            }
        }
        foreach ($g1['cdnur'] as $nt) {
            [$a, $b] = $sumItms($nt['itms']);
            $tx -= $a;
            $tax -= $b;
        }

        $hsnRows = GstnFormat::hsnIsBifurcated($period)
            ? count($g1['hsn']['hsn_b2b']) + count($g1['hsn']['hsn_b2c'])
            : count($g1['hsn']['data']);

        $out3b = $g3['sup_details']['osup_det'];
        $itc = $g3['itc_elg']['itc_net'];
        $outTax = $out3b['iamt'] + $out3b['camt'] + $out3b['samt'];
        $inTax = $itc['iamt'] + $itc['camt'] + $itc['samt'];

        return [
            'period' => $period,
            'period_label' => GstnFormat::periodLabel($period),
            'gstin' => $g1['gstin'],
            'gstr1' => [
                'b2b_invoices' => $b2bInv,
                'b2b_parties' => count($g1['b2b']),
                'b2cl_invoices' => $b2clInv,
                'b2cs_rows' => count($g1['b2cs']),
                'cdnr_notes' => $cdnrNotes,
                'cdnur_notes' => count($g1['cdnur']),
                'hsn_rows' => $hsnRows,
                'doc_ranges' => count($g1['doc_issue']['doc_det']),
                'total_taxable' => GstnFormat::formatMoney($tx),
                'total_tax' => GstnFormat::formatMoney($tax),
            ],
            'gstr3b' => [
                'outward_taxable' => $out3b['txval'],
                'output_igst' => $out3b['iamt'],
                'output_cgst' => $out3b['camt'],
                'output_sgst' => $out3b['samt'],
                'output_tax' => GstnFormat::formatMoney($outTax),
                'itc_igst' => $itc['iamt'],
                'itc_cgst' => $itc['camt'],
                'itc_sgst' => $itc['samt'],
                'itc_total' => GstnFormat::formatMoney($inTax),
                'net_payable' => GstnFormat::formatMoney($outTax - $inTax),
                'inter_state_unreg_rows' => count($g3['inter_sup']['unreg_details']),
            ],
        ];
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * Every tax-bearing voucher of the period, normalised once: party, registration,
     * place of supply, intra/inter, document value, rate slabs (in paise) and item lines.
     */
    private function documents(string $period): Collection
    {
        [$from, $to] = GstnFormat::periodRange($period);

        return Voucher::with(['entries.ledger', 'partyLedger', 'stockEntries.stockItem.unit'])
            ->whereIn('type', self::TAX_VOUCHER_TYPES)
            ->whereNull('vouchers.scenario_id')
            ->whereNotNull('party_ledger_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(fn (Voucher $v) => $this->prepare($v))
            ->filter()
            ->values();
    }

    private function prepare(Voucher $v): ?array
    {
        $party = $v->partyLedger;
        if (! $party) {
            return null;
        }

        $items = $this->taxableLines($v);
        if ($items === []) {
            return null;
        }

        // Reuse the SAME engine that posted the voucher, so the return's tax is the
        // book's tax to the paise — never a second, divergent calculation.
        $comp = $this->gst->computeInvoiceTax(
            $v->type,
            $party->state,
            array_map(fn ($i) => ['amount' => $i['amount'], 'rate' => $i['rate']], $items)
        );

        $ctin = trim((string) $party->gstin);
        $ctin = $ctin !== '' ? $ctin : null;

        $slabs = [];
        foreach ($comp['slabs'] as $s) {
            $slabs[] = [
                'rate' => GstnFormat::formatRate($s['rate']),
                'txval_p' => $s['base_paise'],
                'iamt_p' => $s['igst'],
                'camt_p' => $s['cgst'],
                'samt_p' => $s['sgst'],
                'csamt_p' => 0,
            ];
        }

        return [
            'type' => $v->type,
            'number' => (int) $v->number,
            'display' => $v->displayNumber(),
            'date' => $v->date,
            'party' => $party,
            'ctin' => $ctin,
            'registered' => $ctin !== null,
            'pos' => GstnFormat::placeOfSupply($ctin, $party->state, $this->gst->companyState()) ?? '97',
            'intra' => (bool) $comp['intra'],
            'val' => $this->documentValue($v, $comp),
            'slabs' => $slabs,
            'items' => $items,
        ];
    }

    /**
     * The taxable lines behind a voucher, with the rate that produced its tax.
     *
     * An item invoice's taxable base is the SELLING figure (`sale_value`) on an
     * outward line — never the weighted-average cost — and the entered value on an
     * inward line. An accounting invoice falls back to its nominal ledger lines.
     */
    private function taxableLines(Voucher $v): array
    {
        $out = [];

        if ($v->stockEntries->isNotEmpty()) {
            foreach ($v->stockEntries as $se) {
                $item = $se->stockItem;
                $out[] = [
                    'amount' => $se->sale_value !== null ? (float) $se->sale_value : (float) $se->value,
                    'rate' => (float) ($item?->gst_rate ?? 0),
                    'hsn' => $item?->hsn_sac,
                    'desc' => $item?->name ?? '',
                    'uqc' => GstnFormat::uqc($item?->unit?->symbol),
                    'qty' => (float) $se->quantity,
                ];
            }

            return $out;
        }

        foreach ($v->entries as $e) {
            if ((int) $e->ledger_id === (int) $v->party_ledger_id) {
                continue; // the party leg is the document value, not a taxable line
            }
            $l = $e->ledger;
            if (! $l || $l->tax_type !== null) {
                continue; // a duty ledger (GST or VAT) is tax, not taxable value
            }
            if ($l->is_pl_account) {
                continue; // the computed P&L A/c is never a real posting
            }
            $out[] = [
                'amount' => (float) $e->amount,
                'rate' => (float) ($l->gst_rate ?? 0),
                'hsn' => $l->hsn_sac,
                'desc' => $l->name,
                'uqc' => 'OTH',
                'qty' => 0.0,
            ];
        }

        return $out;
    }

    /** The document's face value — the party leg (tax-inclusive), as posted. */
    private function documentValue(Voucher $v, array $comp): float
    {
        $partyLeg = $v->entries->firstWhere('ledger_id', (int) $v->party_ledger_id);
        if ($partyLeg) {
            return GstnFormat::formatMoney((float) $partyLeg->amount);
        }

        return GstnFormat::paiseToMoney($comp['taxable_paise'] + $comp['tax_paise']);
    }

    /**
     * One `itms[]` entry per rate slab. An intra-state line carries {camt, samt}; an
     * inter-state line carries {iamt} — never both. `$integratedOnly` forces the
     * inter-state shape, which is what B2CL and CDNUR always are.
     */
    private function itms(array $d, bool $integratedOnly = false): array
    {
        $out = [];
        foreach ($d['slabs'] as $s) {
            $det = ['txval' => GstnFormat::paiseToMoney($s['txval_p']), 'rt' => $s['rate']];
            if ($integratedOnly || ! $d['intra']) {
                $det['iamt'] = GstnFormat::paiseToMoney($s['iamt_p']);
            } else {
                $det['camt'] = GstnFormat::paiseToMoney($s['camt_p']);
                $det['samt'] = GstnFormat::paiseToMoney($s['samt_p']);
            }
            $det['csamt'] = GstnFormat::paiseToMoney($s['csamt_p']);

            $out[] = ['num' => GstnFormat::itemNum($s['rate']), 'itm_det' => $det];
        }

        return $out;
    }

    private function isOutwardNote(array $d): bool
    {
        return in_array($d['type'], self::OUTWARD_NOTE_TYPES, true);
    }

    /** Unregistered + inter-state + value strictly above the period's B2CL threshold. */
    private function qualifiesAsLarge(array $d, string $period): bool
    {
        return ! $d['registered']
            && ! $d['intra']
            && GstnFormat::exceedsB2clThreshold($d['val'], $period);
    }

    private function documentLabel(string $type, int $number): string
    {
        $abbr = strtoupper(Voucher::TYPES[$type]['abbr'] ?? $type);

        return $abbr.'-'.$number;
    }
}
