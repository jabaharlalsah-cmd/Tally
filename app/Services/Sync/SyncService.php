<?php

namespace App\Services\Sync;

use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\CostCentre;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\TallyImport\VoucherImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 7C — the desktop sync engine (server side).
 *
 * Runs inside the current tenant's database (the subdomain middleware has already
 * switched the connection). It is the ONLY new posting-adjacent code, and it does
 * NOT re-implement posting: every voucher is posted through the same
 * {@see VoucherScreen::post()} the web UI and the Tally importer use, so the balance
 * gate, GST/VAT authority, bill-wise and cost-centre checks all apply to synced data.
 *
 * PUSH — drain a desktop's offline outbox:
 *   • sorted into strict voucher-date order (reusing the Phase 7A importer's shared
 *     {@see VoucherImporter::chronologicalSort()} — so a day of offline vouchers
 *     entered in any order posts in the order weighted-average COGS requires);
 *   • idempotent on the desktop's client_uuid (a re-sent batch never double-posts);
 *   • server-authoritative — a voucher that fails validation is REJECTED with its
 *     reason (never massaged), the others still post, and the desktop surfaces the
 *     rejection for the user to fix locally.
 *
 * PULL — hand a desktop the changes it hasn't seen (by web SaaS, another desktop, or
 * the importer), using the sync_changes change-log; since=0 returns a full snapshot
 * for the initial local mirror.
 */
class SyncService
{
    private VoucherScreen $screen;

    public function __construct(?VoucherScreen $screen = null)
    {
        $this->screen = $screen ?? new VoucherScreen();
    }

    /**
     * @param  array<int,array{client_uuid:string,seq?:int,payload:array}>  $batch
     * @return array{results: array<int,array>, cursor: int}
     */
    public function push(array $batch): array
    {
        // Fold the voucher date up to the top level so the shared chronological sort
        // can order the batch (weighted-average COGS depends on this — a sale must be
        // posted after the earlier-dated purchase it draws its cost from).
        foreach ($batch as $i => &$e) {
            $e['date'] = $e['payload']['date'] ?? '9999-12-31';
            $e['seq'] = $e['seq'] ?? $i;
        }
        unset($e);
        $ordered = VoucherImporter::chronologicalSort($batch, 'seq');

        $results = [];
        foreach ($ordered as $entry) {
            $uuid = (string) ($entry['client_uuid'] ?? '');

            // Idempotency: a client_uuid already posted (e.g. a retried batch after a
            // lost response) is acknowledged, not re-posted.
            $existing = $uuid !== '' ? Voucher::where('client_uuid', $uuid)->first() : null;
            if ($existing) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'posted', 'server_id' => $existing->id, 'number' => $existing->number, 'duplicate' => true];

                continue;
            }

            try {
                $res = $this->screen->post($entry['payload']);
                $id = $res['voucher']['id'];
                // Stamp the idempotency key without firing model events (the create
                // already logged to sync_changes; this is just the key).
                if ($uuid !== '') {
                    Voucher::whereKey($id)->update(['client_uuid' => $uuid]);
                }
                $results[] = ['client_uuid' => $uuid, 'status' => 'posted', 'server_id' => $id, 'number' => $res['voucher']['number']];
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'reason' => $this->flatten($e)];
            } catch (Throwable $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'reason' => $e->getMessage()];
            }
        }

        return ['results' => $results, 'cursor' => $this->currentCursor()];
    }

    /**
     * Changes a desktop hasn't applied yet. since=0 → a full snapshot for the initial
     * local mirror; since>0 → incremental voucher changes past that cursor.
     */
    public function pull(int $since, int $limit = 500): array
    {
        if ($since <= 0) {
            return $this->snapshot();
        }

        // Phase 12A — sync_changes is queried RAW (no Eloquent scope can help), so
        // the company filter is explicit. 'deleted' ops would otherwise leak other
        // companies' voucher ids to every desktop; created/updated rows would churn
        // the cursor. ActiveCompany::check() fails closed — the sync API always runs
        // behind SetActiveCompany, so a request without a company cannot get here.
        $changes = DB::table('sync_changes')
            ->where('company_id', \App\Support\ActiveCompany::check())
            ->where('entity', 'voucher')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($changes as $c) {
            if ($c->op === 'deleted') {
                $out[] = ['op' => 'deleted', 'cursor' => (int) $c->id, 'id' => (int) $c->record_id];

                continue;
            }
            // Provisional (scenario_id-set) vouchers must NEVER reach a desktop mirror. Their
            // save still logs a sync_changes row, so filter here too (mirroring snapshot()): a
            // provisional voucher resolves to null and is skipped until promotion nulls its tag.
            $v = Voucher::with(['entries.ledger'])->whereNull('scenario_id')->find($c->record_id);
            if ($v) {
                $out[] = ['op' => $c->op, 'cursor' => (int) $c->id, 'voucher' => $v->toRow()];
            }
        }

        return [
            'type' => 'incremental',
            'since' => $since,
            'cursor' => $changes->max('id') ? (int) $changes->max('id') : $since,
            'vouchers' => $out,
        ];
    }

    /** The full tenant state for the initial local mirror. */
    private function snapshot(): array
    {
        return [
            'type' => 'snapshot',
            'cursor' => $this->currentCursor(),
            'features' => CompanyFeature::current()->toFlags(),
            'masters' => [
                'account_groups' => AccountGroup::orderBy('name')->get()->map->toCache()->all(),
                'ledgers' => Ledger::with('group')->orderBy('name')->get()->map->toCache()->all(),
                'cost_centres' => CostCentre::orderBy('name')->get()->map->toCache()->all(),
                'units' => Unit::orderBy('name')->get()->map->toCache()->all(),
                'stock_groups' => StockGroup::orderBy('name')->get()->map->toCache()->all(),
                'godowns' => Godown::orderBy('name')->get()->map->toCache()->all(),
                'stock_items' => StockItem::with(['stockGroup', 'unit'])->orderBy('name')->get()->map->toCache()->all(),
            ],
            'vouchers' => Voucher::with(['entries.ledger'])->whereNull('scenario_id')->orderBy('date')->orderBy('id')->get()->map->toRow()->all(),
        ];
    }

    public function currentCursor(): int
    {
        return (int) DB::table('sync_changes')->max('id');
    }

    private function flatten(ValidationException $e): string
    {
        $msgs = [];
        foreach ($e->errors() as $errors) {
            foreach ($errors as $m) {
                $msgs[] = $m;
            }
        }

        return implode(' ', $msgs) ?: $e->getMessage();
    }
}
