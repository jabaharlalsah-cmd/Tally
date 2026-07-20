<?php

namespace App\Services\TallyImport;

use App\Models\AccountGroup;
use App\Models\CostCentre;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use RuntimeException;

/**
 * Maps every parsed Tally master to its ZeroBook counterpart (Phase 7A).
 *
 * Two jobs:
 *   1. Create the masters ZeroBook doesn't already have — in dependency order, and
 *      honouring Tally's parent chains (a child group/godown/cost-centre may be
 *      exported before its parent, so parents are resolved recursively).
 *   2. REUSE, never duplicate, anything ZeroBook already seeds under the same name:
 *      the 28 predefined account groups, the reserved Cash / Profit & Loss / GST-VAT
 *      duty ledgers, and the reserved "Main Location" godown. A reused ledger still
 *      picks up its Tally opening balance and party details; its group, reserved
 *      status and tax role are left untouched.
 *
 * After {@see resolve()} the public name→id maps are complete, so the voucher
 * importer's per-line lookups are O(1) and never touch the database inside the
 * streaming loop.
 *
 * Runs inside the importer's single transaction — a dry-run rolls all of this back.
 */
class Resolver
{
    /** @var array<string,int> */ public array $groups = [];
    /** @var array<string,int> */ public array $ledgers = [];
    /** @var array<string,int> */ public array $stockGroups = [];
    /** @var array<string,int> */ public array $units = [];
    /** @var array<string,int> */ public array $godowns = [];
    /** @var array<string,int> */ public array $costCentres = [];
    /** @var array<string,int> */ public array $stockItems = [];

    /** @var array<string,array{created:int,reused:int}> per-entity tallies for the report */
    public array $counts = [];

    /** @var array<int,string> non-fatal notes (e.g. an assumed nature) */
    public array $notes = [];

    /** Parsed masters keyed by name, for recursive parent resolution. */
    private array $groupByName = [];
    private array $stockGroupByName = [];
    private array $godownByName = [];
    private array $costCentreByName = [];

    /** Cycle guards for the recursive parent walks. */
    private array $resolving = [];

    public function resolve(array $masters): void
    {
        $this->index($masters);

        // Dependency order: leaves first, then anything that references them.
        foreach ($masters['units'] as $u) {
            $this->ensureUnit($u);
        }
        foreach ($masters['stock_groups'] as $g) {
            $this->ensureStockGroup($g['name']);
        }
        foreach ($masters['godowns'] as $g) {
            $this->ensureGodown($g['name']);
        }
        foreach ($masters['cost_centres'] as $c) {
            $this->ensureCostCentre($c['name']);
        }
        foreach ($masters['groups'] as $g) {
            $this->ensureGroup($g['name']);
        }
        foreach ($masters['ledgers'] as $l) {
            $this->ensureLedger($l);
        }
        foreach ($masters['stock_items'] as $i) {
            $this->ensureStockItem($i);
        }
    }

    private function index(array $masters): void
    {
        foreach ($masters['groups'] as $g) {
            $this->groupByName[$g['name']] = $g;
        }
        foreach ($masters['stock_groups'] as $g) {
            $this->stockGroupByName[$g['name']] = $g;
        }
        foreach ($masters['godowns'] as $g) {
            $this->godownByName[$g['name']] = $g;
        }
        foreach ($masters['cost_centres'] as $c) {
            $this->costCentreByName[$c['name']] = $c;
        }
    }

    // ── Account groups ────────────────────────────────────────────────────────

    private function ensureGroup(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Encountered an account group with no name.');
        }
        if (isset($this->groups[$name])) {
            return $this->groups[$name];
        }
        // Reuse a group ZeroBook already has under this name (the seeded 28, or one
        // created earlier in this same import).
        $existing = AccountGroup::where('name', $name)->first();
        if ($existing) {
            $this->tally('groups', 'reused');

            return $this->groups[$name] = $existing->id;
        }

        // A brand-new (custom) group — resolve its parent chain first.
        $parsed = $this->groupByName[$name] ?? null;
        $parentName = trim((string) ($parsed['parent'] ?? ''));

        $this->guard('group', $name);
        $parentId = $parentName !== '' ? $this->ensureGroup($parentName) : null;
        $this->unguard('group', $name);

        if ($parentId) {
            $nature = (string) AccountGroup::whereKey($parentId)->value('nature');
        } else {
            // A custom PRIMARY group with no nature hint in the file — assume the most
            // conservative bucket and flag it for the operator (does not occur in a
            // normal export, where every referenced parent is present).
            $nature = 'Liabilities';
            $this->notes[] = "Custom primary group “{$name}” imported with assumed nature Liabilities — verify.";
        }

        $id = AccountGroup::create([
            'name' => $name,
            'parent_id' => $parentId,
            'nature' => $nature,
            'is_primary' => $parentId === null,
            'is_reserved' => false,
        ])->id;

        $this->tally('groups', 'created');

        return $this->groups[$name] = $id;
    }

    // ── Ledgers ───────────────────────────────────────────────────────────────

    private function ensureLedger(array $l): int
    {
        $name = trim($l['name']);
        if (isset($this->ledgers[$name])) {
            return $this->ledgers[$name];
        }

        $existing = Ledger::where('name', $name)->first();
        if ($existing) {
            // Reuse the reserved/seeded ledger (Cash, P&L, GST/VAT duty ledgers) but
            // carry its Tally opening balance and party details. NEVER touch its group,
            // reserved flag or tax role — those define the reserved ledger's identity.
            $updates = $this->ledgerImportableFields($l, includeGroup: false);
            if ($updates) {
                $existing->update($updates);
            }
            $this->tally('ledgers', 'reused');

            return $this->ledgers[$name] = $existing->id;
        }

        $groupName = trim((string) ($l['parent'] ?? ''));
        $groupId = $groupName !== '' ? $this->ensureGroup($groupName) : null;
        if (! $groupId) {
            throw new RuntimeException("Ledger “{$name}” has no resolvable group (Under: “{$groupName}”).");
        }

        $fields = array_merge(
            ['name' => $name, 'group_id' => $groupId, 'is_reserved' => false, 'is_pl_account' => false],
            $this->ledgerImportableFields($l, includeGroup: false),
        );
        $id = Ledger::create($fields)->id;
        $this->tally('ledgers', 'created');

        return $this->ledgers[$name] = $id;
    }

    /**
     * The Tally fields ZeroBook carries onto a ledger — opening balance (with Dr/Cr
     * direction), party GST identity, the F11 flags, and bank details. Deliberately
     * excludes tax_type/tax_role (those belong only to the seeded duty ledgers).
     */
    private function ledgerImportableFields(array $l, bool $includeGroup): array
    {
        $out = [];
        if ($l['opening_side'] !== null) {
            $out['opening_balance'] = round((float) $l['opening_mag'], 2);
            $out['opening_balance_type'] = $l['opening_side'];
        }
        foreach ([
            'state' => 'state', 'country' => 'country', 'gstin' => 'gstin', 'pan' => 'pan',
            'gst_registration_type' => 'gst_registration_type', 'hsn' => 'hsn_sac',
            'bank_account_no' => 'bank_account_no', 'bank_ifsc' => 'bank_ifsc', 'bank_name' => 'bank_name',
        ] as $src => $col) {
            if (! empty($l[$src])) {
                $out[$col] = $l[$src];
            }
        }
        if ($l['gst_rate'] !== null) {
            $out['gst_rate'] = round((float) $l['gst_rate'], 2);
        }
        $out['maintain_bill_by_bill'] = (bool) $l['bill_by_bill'];
        $out['cost_centres_applicable'] = (bool) $l['cost_centres'];

        return $out;
    }

    // ── Stock groups / items / units / godowns ────────────────────────────────

    private function ensureStockGroup(string $name): int
    {
        $name = trim($name);
        if ($name === '' || isset($this->stockGroups[$name])) {
            return $this->stockGroups[$name] ?? 0;
        }
        $existing = StockGroup::where('name', $name)->first();
        if ($existing) {
            $this->tally('stock_groups', 'reused');

            return $this->stockGroups[$name] = $existing->id;
        }
        $parsed = $this->stockGroupByName[$name] ?? null;
        $parentName = trim((string) ($parsed['parent'] ?? ''));
        $this->guard('stockgroup', $name);
        $parentId = $parentName !== '' ? $this->ensureStockGroup($parentName) : null;
        $this->unguard('stockgroup', $name);

        $id = StockGroup::create(['name' => $name, 'parent_id' => $parentId ?: null])->id;
        $this->tally('stock_groups', 'created');

        return $this->stockGroups[$name] = $id;
    }

    private function ensureUnit(array $u): int
    {
        $name = trim($u['name']);
        if ($name === '' || isset($this->units[$name])) {
            return $this->units[$name] ?? 0;
        }
        $existing = Unit::where('name', $name)->first();
        if ($existing) {
            $this->tally('units', 'reused');

            return $this->units[$name] = $existing->id;
        }
        $id = Unit::create([
            'name' => $name,
            'symbol' => $u['symbol'] ?: $name,
            'decimal_places' => (int) $u['decimals'],
        ])->id;
        $this->tally('units', 'created');

        return $this->units[$name] = $id;
    }

    private function ensureGodown(string $name): int
    {
        $name = trim($name);
        if ($name === '' || isset($this->godowns[$name])) {
            return $this->godowns[$name] ?? 0;
        }
        $existing = Godown::where('name', $name)->first();
        if ($existing) {
            $this->tally('godowns', 'reused');

            return $this->godowns[$name] = $existing->id;
        }
        $parsed = $this->godownByName[$name] ?? null;
        $parentName = trim((string) ($parsed['parent'] ?? ''));
        $this->guard('godown', $name);
        $parentId = $parentName !== '' ? $this->ensureGodown($parentName) : null;
        $this->unguard('godown', $name);

        $id = Godown::create(['name' => $name, 'parent_id' => $parentId ?: null, 'is_reserved' => false])->id;
        $this->tally('godowns', 'created');

        return $this->godowns[$name] = $id;
    }

    private function ensureCostCentre(string $name): int
    {
        $name = trim($name);
        if ($name === '' || isset($this->costCentres[$name])) {
            return $this->costCentres[$name] ?? 0;
        }
        $existing = CostCentre::where('name', $name)->first();
        if ($existing) {
            $this->tally('cost_centres', 'reused');

            return $this->costCentres[$name] = $existing->id;
        }
        $parsed = $this->costCentreByName[$name] ?? null;
        $parentName = trim((string) ($parsed['parent'] ?? ''));
        $this->guard('costcentre', $name);
        $parentId = $parentName !== '' ? $this->ensureCostCentre($parentName) : null;
        $this->unguard('costcentre', $name);

        $id = CostCentre::create(['name' => $name, 'parent_id' => $parentId ?: null])->id;
        $this->tally('cost_centres', 'created');

        return $this->costCentres[$name] = $id;
    }

    private function ensureStockItem(array $i): int
    {
        $name = trim($i['name']);
        if (isset($this->stockItems[$name])) {
            return $this->stockItems[$name];
        }
        $existing = StockItem::where('name', $name)->first();
        if ($existing) {
            $this->tally('stock_items', 'reused');

            return $this->stockItems[$name] = $existing->id;
        }

        $stockGroupId = ! empty($i['parent']) ? $this->ensureStockGroup($i['parent']) : null;
        $unitId = ! empty($i['base_unit']) ? $this->ensureUnit(['name' => $i['base_unit'], 'symbol' => $i['base_unit'], 'decimals' => 0]) : null;
        $godownId = ! empty($i['opening_godown']) ? $this->ensureGodown($i['opening_godown']) : null;

        $id = StockItem::create([
            'name' => $name,
            'stock_group_id' => $stockGroupId ?: null,
            'unit_id' => $unitId ?: null,
            'opening_qty' => round((float) $i['opening_qty'], 4),
            'opening_rate' => round((float) $i['opening_rate'], 4),
            'opening_value' => round((float) $i['opening_value'], 2),
            'opening_godown_id' => $godownId ?: null,
            'gst_rate' => $i['gst_rate'] !== null ? round((float) $i['gst_rate'], 2) : null,
            'hsn_sac' => $i['hsn'] ?: null,
            'costing_method' => 'weighted_average',
        ])->id;
        $this->tally('stock_items', 'created');

        return $this->stockItems[$name] = $id;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function tally(string $entity, string $kind): void
    {
        $this->counts[$entity] ??= ['created' => 0, 'reused' => 0];
        $this->counts[$entity][$kind]++;
    }

    private function guard(string $kind, string $name): void
    {
        $key = $kind.'|'.$name;
        if (isset($this->resolving[$key])) {
            throw new RuntimeException("Cyclic parent chain detected at {$kind} “{$name}”.");
        }
        $this->resolving[$key] = true;
    }

    private function unguard(string $kind, string $name): void
    {
        unset($this->resolving[$kind.'|'.$name]);
    }
}
