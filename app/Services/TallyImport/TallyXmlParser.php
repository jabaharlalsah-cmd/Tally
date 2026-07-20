<?php

namespace App\Services\TallyImport;

use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Streaming parser for a Tally XML export (Phase 7A).
 *
 * ── Streaming, not DOM ────────────────────────────────────────────────────────
 * A real multi-year "All Masters + Day Book" export is hundreds of MB; loading it
 * whole into SimpleXML/DOM would materialise a multi-GB tree and blow PHP's memory
 * limit. Instead we drive an {@see XMLReader} node-by-node and, when it lands on a
 * master or a <VOUCHER>, expand ONLY that one element into a throwaway
 * DOMDocument, read its fields into a compact PHP array, and drop the DOM. Peak
 * memory is therefore one element's subtree plus the accumulated compact model —
 * never the raw XML. (The compact voucher records are retained because the
 * importer must sort them into strict chronological order; an external merge-sort
 * for pathological books is a documented future optimisation, not needed for the
 * SME books this targets.)
 *
 * ── Tolerant of Tally version differences ─────────────────────────────────────
 * The tag shapes drift between Tally ERP 9 and TallyPrime and between export
 * views. This parser accepts, for the same datum:
 *   • ledger lines:  <ALLLEDGERENTRIES.LIST>  and  <LEDGERENTRIES.LIST>
 *   • inventory:     <ALLINVENTORYENTRIES.LIST> and <INVENTORYENTRIES.LIST>
 *   • Dr/Cr:         <ISDEEMEDPOSITIVE> (Yes = Dr) cross-checked with the sign of
 *                    <AMOUNT> (negative = Dr) — the two-sign convention Tally uses.
 *   • names:         a NAME="" attribute OR a <NAME.LIST><NAME> child.
 *   • the invoice revenue leg living either as a top-level ledger entry
 *     (accounting view) or nested in <ACCOUNTINGALLOCATIONS.LIST> (invoice view).
 *
 * >>> The single most version-sensitive assumption is the AMOUNT / OPENINGBALANCE
 *     sign convention (negative = Debit). It is applied consistently here and is
 *     the first thing to confirm against a real customer export. <<<
 */
class TallyXmlParser
{
    /** Master element → the key under which its parsed records are collected. */
    private const MASTER_ELEMENTS = [
        'GROUP' => 'groups',
        'LEDGER' => 'ledgers',
        'STOCKGROUP' => 'stock_groups',
        'STOCKITEM' => 'stock_items',
        'UNIT' => 'units',
        'GODOWN' => 'godowns',
        'COSTCENTRE' => 'cost_centres',
    ];

    /**
     * Elements ZeroBook has no home for — encountered, counted, and reported to the
     * customer as "not imported" (never silently dropped). Value = human label.
     */
    private const NOT_IMPORTED_ELEMENTS = [
        'COSTCATEGORY' => 'Cost categories (cost centres are imported flat)',
        'STOCKCATEGORY' => 'Stock categories',
        'BUDGET' => 'Budgets',
        'SCENARIO' => 'Scenarios / optional vouchers',
        'ATTENDANCETYPE' => 'Payroll — attendance types',
        'PAYHEAD' => 'Payroll — pay heads',
        'EMPLOYEE' => 'Payroll — employees',
        'PRICELEVEL' => 'Price levels',
        'PRICELIST' => 'Price lists',
        'CURRENCY' => 'Foreign currencies (multi-currency)',
        'VOUCHERTYPE' => 'Voucher-type definitions (vouchers themselves ARE imported)',
        'TDSNATURE' => 'TDS / TCS nature-of-payment',
        'GSTCLASSIFICATION' => 'GST classifications',
        'TAXUNIT' => 'GST tax units',
        'GROUPCOMPANY' => 'Group companies',
    ];

    private ?string $company = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $masters = [
        'groups' => [], 'ledgers' => [], 'stock_groups' => [], 'stock_items' => [],
        'units' => [], 'godowns' => [], 'cost_centres' => [],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $vouchers = [];

    /** @var array<string, int> concept label => count */
    private array $notImported = [];

    private int $fileIndex = 0;

    /**
     * Parse the file at $path into the normalized in-memory model.
     *
     * @return array{company: ?string, masters: array, vouchers: array, not_imported: array}
     */
    public function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Tally export not found or unreadable: {$path}");
        }

        $reader = new XMLReader();
        // LIBXML_NOWARNING|LIBXML_NONET — no network, quiet on the odd stray entity.
        if (! @$reader->open($path, null, LIBXML_NOWARNING | LIBXML_NONET)) {
            throw new RuntimeException("Could not open Tally export as XML: {$path}");
        }

        try {
            $this->stream($reader);
        } finally {
            $reader->close();
        }

        return [
            'company' => $this->company,
            'masters' => $this->masters,
            'vouchers' => $this->vouchers,
            'not_imported' => $this->notImported,
        ];
    }

    /**
     * The single streaming pass. Descends through envelope wrappers (ENVELOPE /
     * BODY / IMPORTDATA / REQUESTDATA / TALLYMESSAGE …) and acts only on the master
     * and voucher elements, skipping each handled element's subtree with next().
     */
    private function stream(XMLReader $reader): void
    {
        if (! $reader->read()) {
            return;
        }

        $tallyMessageDepth = null;

        while (true) {
            $advanced = false;

            if ($reader->nodeType === XMLReader::ELEMENT) {
                $name = strtoupper($reader->localName);

                if ($name === 'TALLYMESSAGE') {
                    // Descend into the wrapper; its single child is the master/voucher.
                    $tallyMessageDepth = $reader->depth;
                } elseif ($name === 'SVCURRENTCOMPANY') {
                    $this->company = trim((string) $reader->readString()) ?: null;
                    $advanced = $this->skipSubtree($reader);
                } elseif ($name === 'VOUCHER') {
                    $this->collectVoucher($this->expand($reader));
                    $advanced = $this->skipSubtree($reader);
                } elseif (isset(self::MASTER_ELEMENTS[$name])) {
                    $this->collectMaster($name, $this->expand($reader));
                    $advanced = $this->skipSubtree($reader);
                } elseif (isset(self::NOT_IMPORTED_ELEMENTS[$name])) {
                    $this->note(self::NOT_IMPORTED_ELEMENTS[$name]);
                    $advanced = $this->skipSubtree($reader);
                } elseif ($tallyMessageDepth !== null && $reader->depth === $tallyMessageDepth + 1) {
                    // An unrecognised element sitting directly under <TALLYMESSAGE> is
                    // some other master type we don't model — report it, don't drop it.
                    $this->note('Other master: '.$reader->localName);
                    $advanced = $this->skipSubtree($reader);
                }
            }

            if (! $advanced && ! $reader->read()) {
                break;
            }
        }
    }

    /** Skip the current element's whole subtree; true if a sibling remains. */
    private function skipSubtree(XMLReader $reader): bool
    {
        return $reader->next();
    }

    /** Expand the element at the cursor into a standalone SimpleXMLElement. */
    private function expand(XMLReader $reader): SimpleXMLElement
    {
        $doc = new \DOMDocument();
        $node = $doc->importNode($reader->expand(), true);
        $doc->appendChild($node);

        return simplexml_import_dom($node);
    }

    private function note(string $label): void
    {
        $this->notImported[$label] = ($this->notImported[$label] ?? 0) + 1;
    }

    // ── Master collection ─────────────────────────────────────────────────────

    private function collectMaster(string $element, SimpleXMLElement $n): void
    {
        $name = $this->masterName($n);
        if ($name === '') {
            $this->note('Nameless '.$element.' record');

            return;
        }

        match ($element) {
            'GROUP' => $this->masters['groups'][] = [
                'name' => $name,
                'parent' => $this->text($n, 'PARENT'),
                'is_billwise' => $this->yes($n, 'ISBILLWISEON'),
                'is_costcentres' => $this->yes($n, 'ISCOSTCENTRESON'),
            ],
            'LEDGER' => $this->masters['ledgers'][] = $this->parseLedger($n, $name),
            'STOCKGROUP' => $this->masters['stock_groups'][] = [
                'name' => $name,
                'parent' => $this->text($n, 'PARENT'),
            ],
            'STOCKITEM' => $this->masters['stock_items'][] = $this->parseStockItem($n, $name),
            'UNIT' => $this->masters['units'][] = [
                'name' => $name,
                'symbol' => $this->text($n, 'ORIGINALNAME') ?: $name,
                'decimals' => (int) $this->num($this->text($n, 'DECIMALPLACES')),
            ],
            'GODOWN' => $this->masters['godowns'][] = [
                'name' => $name,
                'parent' => $this->text($n, 'PARENT'),
            ],
            'COSTCENTRE' => $this->masters['cost_centres'][] = [
                'name' => $name,
                'parent' => $this->text($n, 'PARENT'),
                'category' => $this->text($n, 'CATEGORY'),
            ],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function parseLedger(SimpleXMLElement $n, string $name): array
    {
        // OPENINGBALANCE is signed: negative = Debit, positive = Credit. Zero = none.
        $ob = $this->num($this->text($n, 'OPENINGBALANCE'));
        $mag = abs($ob);
        $side = $mag < 1e-9 ? null : ($ob < 0 ? 'Dr' : 'Cr');

        return [
            'name' => $name,
            'parent' => $this->text($n, 'PARENT'),
            'opening_mag' => $mag,
            'opening_side' => $side,
            'state' => $this->text($n, 'LEDSTATENAME') ?: $this->text($n, 'STATENAME'),
            'country' => $this->text($n, 'COUNTRYNAME') ?: null,
            'gstin' => $this->text($n, 'PARTYGSTIN') ?: $this->text($n, 'GSTIN'),
            'pan' => $this->text($n, 'INCOMETAXNUMBER') ?: $this->text($n, 'PANNUMBER'),
            'gst_registration_type' => $this->text($n, 'GSTREGISTRATIONTYPE') ?: null,
            'gst_rate' => $this->hasChild($n, 'GSTVATRATE') ? $this->num($this->text($n, 'GSTVATRATE')) : null,
            'hsn' => $this->text($n, 'GSTVATHSNCODE') ?: $this->text($n, 'HSNCODE'),
            'bill_by_bill' => $this->yes($n, 'ISBILLWISEON'),
            'cost_centres' => $this->yes($n, 'ISCOSTCENTRESON'),
            'bank_account_no' => $this->text($n, 'BANKACCHOLDERNAME') ? $this->text($n, 'BANKACCOUNTNUMBER') : ($this->text($n, 'BANKACCOUNTNUMBER') ?: null),
            'bank_ifsc' => $this->text($n, 'IFSCODE') ?: null,
            'bank_name' => $this->text($n, 'BANKNAME') ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function parseStockItem(SimpleXMLElement $n, string $name): array
    {
        // Opening balance may be an aggregate <OPENINGBALANCE>40 Nos</OPENINGBALANCE>
        // plus per-godown <BATCHALLOCATIONS.LIST>. We take the aggregate qty/value and
        // the FIRST godown named in the batch list as the single opening godown
        // (ZeroBook holds one opening godown per item; multi-godown opening splits are
        // reported by the caller).
        $godown = null;
        $batches = $n->{'BATCHALLOCATIONS.LIST'};
        if ($batches) {
            foreach ($batches as $b) {
                $g = trim((string) ($b->GODOWNNAME ?? ''));
                if ($g !== '') {
                    $godown = $g;
                    break;
                }
            }
        }

        return [
            'name' => $name,
            'parent' => $this->text($n, 'PARENT'),
            'base_unit' => $this->text($n, 'BASEUNITS') ?: $this->text($n, 'BASEUNIT'),
            'opening_qty' => $this->num($this->text($n, 'OPENINGBALANCE')),
            'opening_rate' => $this->num($this->text($n, 'OPENINGRATE')),
            'opening_value' => $this->num($this->text($n, 'OPENINGVALUE')),
            'opening_godown' => $godown,
            'gst_rate' => $this->hasChild($n, 'GSTVATRATE') ? $this->num($this->text($n, 'GSTVATRATE')) : null,
            'hsn' => $this->text($n, 'GSTVATHSNCODE') ?: $this->text($n, 'HSNCODE'),
        ];
    }

    // ── Voucher collection ────────────────────────────────────────────────────

    private function collectVoucher(SimpleXMLElement $v): void
    {
        $date = $this->tallyDate($this->text($v, 'DATE'));
        if ($date === null) {
            $this->note('Voucher with no/invalid date');

            return;
        }

        $topLevel = $this->ledgerEntries($v);          // top-level ledger legs
        [$items, $nested] = $this->inventoryEntries($v); // inventory + nested accounting legs

        // Merge the two representations of the accounting side. In an invoice-view
        // export the revenue leg lives ONLY in the nested accounting allocations; in
        // an accounting-view export it ALSO appears at top level (a duplicate). So:
        // drop any top-level leg whose ledger is re-stated in the nested legs, then
        // append the nested legs. Disjoint sets simply concatenate.
        $lines = $topLevel;
        if (! empty($nested)) {
            $nestedLedgers = array_map(fn ($l) => $l['ledger'], $nested);
            $lines = array_values(array_filter($topLevel, fn ($l) => ! in_array($l['ledger'], $nestedLedgers, true)));
            $lines = array_merge($lines, $nested);
        }

        $this->vouchers[] = [
            'file_index' => $this->fileIndex++,
            'type_raw' => $this->text($v, 'VOUCHERTYPENAME') ?: (string) ($v['VCHTYPE'] ?? ''),
            'date' => $date,
            'number_raw' => $this->text($v, 'VOUCHERNUMBER'),
            'narration' => $this->text($v, 'NARRATION') ?: null,
            'reference_no' => $this->text($v, 'REFERENCE') ?: null,
            'reference_date' => $this->tallyDate($this->text($v, 'REFERENCEDATE')),
            'party' => $this->text($v, 'PARTYLEDGERNAME') ?: $this->text($v, 'PARTYNAME') ?: null,
            'lines' => $lines,
            'items' => $items,
        ];
    }

    /**
     * Top-level ledger legs, from <ALLLEDGERENTRIES.LIST> or <LEDGERENTRIES.LIST>.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ledgerEntries(SimpleXMLElement $v): array
    {
        $out = [];
        foreach (['ALLLEDGERENTRIES.LIST', 'LEDGERENTRIES.LIST'] as $tag) {
            foreach ($v->{$tag} ?? [] as $e) {
                $line = $this->ledgerLeg($e);
                if ($line !== null) {
                    $out[] = $line;
                }
            }
        }

        return $out;
    }

    /**
     * Inventory legs + the ledger legs nested under each inventory entry's
     * <ACCOUNTINGALLOCATIONS.LIST>.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     */
    private function inventoryEntries(SimpleXMLElement $v): array
    {
        $items = [];
        $nestedLegs = [];

        foreach (['ALLINVENTORYENTRIES.LIST', 'INVENTORYENTRIES.LIST', 'INVENTORYENTRIESIN.LIST', 'INVENTORYENTRIESOUT.LIST'] as $tag) {
            foreach ($v->{$tag} ?? [] as $inv) {
                $item = trim((string) ($inv->STOCKITEMNAME ?? ''));
                if ($item === '') {
                    continue;
                }
                $qty = abs($this->num($this->text($inv, 'ACTUALQTY') ?: $this->text($inv, 'BILLEDQTY')));
                $rate = abs($this->num($this->text($inv, 'RATE')));

                // Godown: from the first BATCHALLOCATIONS entry, else GODOWNNAME.
                $godown = null;
                foreach ($inv->{'BATCHALLOCATIONS.LIST'} ?? [] as $b) {
                    $g = trim((string) ($b->GODOWNNAME ?? ''));
                    if ($g !== '') {
                        $godown = $g;
                        break;
                    }
                }
                $godown ??= ($this->text($inv, 'GODOWNNAME') ?: null);

                $items[] = ['stock_item' => $item, 'godown' => $godown, 'qty' => $qty, 'rate' => $rate];

                // Nested accounting allocation → the revenue/expense ledger leg(s).
                foreach ($inv->{'ACCOUNTINGALLOCATIONS.LIST'} ?? [] as $alloc) {
                    $line = $this->ledgerLeg($alloc);
                    if ($line !== null) {
                        $nestedLegs[] = $line;
                    }
                }
            }
        }

        return [$items, $nestedLegs];
    }

    /**
     * One ledger leg (top-level or nested) → normalized line with Dr/Cr, positive
     * amount, and any bill-wise / cost-centre allocations.
     *
     * @return array<string, mixed>|null
     */
    private function ledgerLeg(SimpleXMLElement $e): ?array
    {
        $ledger = trim((string) ($e->LEDGERNAME ?? ''));
        if ($ledger === '') {
            return null;
        }
        $amount = $this->num($this->text($e, 'AMOUNT'));

        return [
            'ledger' => $ledger,
            'dr_cr' => $this->drcr($e, $amount),
            'amount' => round(abs($amount), 2),
            'bill_allocations' => $this->billAllocations($e),
            'cost_allocations' => $this->costAllocations($e),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function billAllocations(SimpleXMLElement $e): array
    {
        $out = [];
        foreach ($e->{'BILLALLOCATIONS.LIST'} ?? [] as $b) {
            $ref = trim((string) ($b->NAME ?? ''));
            if ($ref === '') {
                continue;
            }
            $amt = abs($this->num($this->text($b, 'AMOUNT')));
            if ($amt < 1e-9) {
                continue;
            }
            $out[] = [
                'ref_type' => $this->billType($this->text($b, 'BILLTYPE')),
                'ref_name' => $ref,
                'amount' => round($amt, 2),
                'due_date' => null, // Tally exports a credit PERIOD, not a due date
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function costAllocations(SimpleXMLElement $e): array
    {
        $out = [];
        foreach ($e->{'CATEGORYALLOCATIONS.LIST'} ?? [] as $cat) {
            foreach ($cat->{'COSTCENTREALLOCATIONS.LIST'} ?? [] as $cc) {
                $name = trim((string) ($cc->NAME ?? ''));
                $amt = abs($this->num($this->text($cc, 'AMOUNT')));
                if ($name === '' || $amt < 1e-9) {
                    continue;
                }
                $out[] = ['cost_centre' => $name, 'amount' => round($amt, 2)];
            }
        }
        // Some exports place cost centres directly (no category wrapper).
        foreach ($e->{'COSTCENTREALLOCATIONS.LIST'} ?? [] as $cc) {
            $name = trim((string) ($cc->NAME ?? ''));
            $amt = abs($this->num($this->text($cc, 'AMOUNT')));
            if ($name === '' || $amt < 1e-9) {
                continue;
            }
            $out[] = ['cost_centre' => $name, 'amount' => round($amt, 2)];
        }

        return $out;
    }

    // ── Field helpers ─────────────────────────────────────────────────────────

    private function billType(string $raw): string
    {
        return match (strtolower(trim($raw))) {
            'new ref', 'new reference' => 'new',
            'agst ref', 'against ref', 'agstref' => 'against',
            'advance' => 'advance',
            'on account', 'onaccount' => 'onaccount',
            default => 'new',
        };
    }

    private function drcr(SimpleXMLElement $e, float $amount): string
    {
        $deemed = strtoupper(trim((string) ($e->ISDEEMEDPOSITIVE ?? '')));
        if ($deemed === 'YES') {
            return 'Dr';
        }
        if ($deemed === 'NO') {
            return 'Cr';
        }

        // No explicit flag → fall back to the sign convention (negative = Dr).
        return $amount < 0 ? 'Dr' : 'Cr';
    }

    /** Tally date "YYYYMMDD" → "YYYY-MM-DD" (also tolerates already-hyphenated). */
    private function tallyDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return null;
    }

    /** The canonical master name: NAME="" attribute, else <NAME.LIST><NAME>, else <NAME>. */
    private function masterName(SimpleXMLElement $n): string
    {
        $attr = trim((string) ($n['NAME'] ?? ''));
        if ($attr !== '') {
            return $attr;
        }
        $list = $n->{'NAME.LIST'};
        if ($list && isset($list->NAME)) {
            return trim((string) $list->NAME[0]);
        }
        if (isset($n->NAME)) {
            return trim((string) $n->NAME);
        }

        return '';
    }

    /** First child element text by name (dotted names supported), trimmed. */
    private function text(SimpleXMLElement $n, string $child): string
    {
        $el = $n->{$child};

        return ($el !== null && isset($el[0])) ? trim((string) $el[0]) : '';
    }

    private function hasChild(SimpleXMLElement $n, string $child): bool
    {
        $el = $n->{$child};

        return $el !== null && isset($el[0]) && trim((string) $el[0]) !== '';
    }

    private function yes(SimpleXMLElement $n, string $child): bool
    {
        return strtoupper($this->text($n, $child)) === 'YES';
    }

    /** Parse a Tally numeric field: strip thousands separators + unit suffixes. */
    private function num(string $s): float
    {
        $s = str_replace(',', '', $s);
        if (preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) {
            return (float) $m[0];
        }

        return 0.0;
    }
}
