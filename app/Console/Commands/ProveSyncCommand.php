<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Models\AccountGroup;
use App\Models\CompanyFeature;
use App\Models\Godown;
use App\Models\Ledger;
use App\Models\StockEntry;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\Sync\SyncService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 7C numeric proof of the desktop SYNC engine (server side).
 *
 * Provisions a throwaway tenant, then proves — against a real tenant database —
 * everything the desktop's offline outbox depends on:
 *
 *   • CHRONOLOGICAL PUSH: a batch entered offline in the WRONG order (a Sale dated
 *     later, sent BEFORE the Purchase it draws its cost from) is posted in
 *     voucher-date order, so the sale's weighted-average COGS is correct — the
 *     single subtlest sync-correctness point.
 *   • SERVER-AUTHORITATIVE REJECTION: an out-of-balance voucher in the batch is
 *     rejected with its reason (the desktop surfaces it), the good ones still post.
 *   • IDEMPOTENCY: re-sending a batch (a retried outbox drain) never double-posts.
 *   • ROUND-TRIP: a synced voucher is a normal server voucher (same id, in the Day
 *     Book) — identical to what the web SaaS would show.
 *   • PULL: since=0 returns a full snapshot; an incremental pull returns only the
 *     changes past the cursor.
 *
 * Everything posts through the SAME VoucherScreen::post() — no parallel posting.
 */
class ProveSyncCommand extends Command
{
    use ResolvesActiveCompany;
    protected $signature = 'zerobook:prove-sync {--keep : keep the synctest tenant provisioned} {--company= : run in this company (slug or id); default = the throwaway tenant’s default company}';

    protected $description = 'Prove the desktop sync engine: chronological push, server-authoritative rejection, idempotency, round-trip, pull';

    private bool $ok = true;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'synctest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Sync Test Co', 'professional');

            Tenant::find($slug)->run(function () {
                // Phase 12A — pin the throwaway tenant's default company as active
                // before any scoped model is touched (CLI has no session).
                if (! $this->resolveActiveCompany()) {
                    throw new \RuntimeException('No default company in the throwaway tenant.');
                }

                CompanyFeature::current()->update(['gst' => false, 'vat' => false, 'bill_by_bill' => false, 'cost_centres' => false]);
                $sync = new SyncService();

                // ── masters: Widget opening 40 @ 25 = 1,000, + two parties + nominals
                $gid = fn ($n) => AccountGroup::where('name', $n)->value('id');
                $unit = Unit::create(['name' => 'Nos', 'symbol' => 'Nos', 'decimal_places' => 0]);
                $grp = StockGroup::create(['name' => 'Finished Goods']);
                $godown = Godown::where('name', 'Main Location')->value('id');
                $widget = StockItem::create(['name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
                    'opening_qty' => 40, 'opening_rate' => 25, 'opening_value' => 1000, 'opening_godown_id' => $godown, 'costing_method' => 'weighted_average']);
                $abc = Ledger::create(['name' => 'ABC Retail', 'group_id' => $gid('Sundry Debtors'), 'country' => 'India']);
                $modern = Ledger::create(['name' => 'Modern Traders', 'group_id' => $gid('Sundry Creditors'), 'country' => 'India']);
                $sales = Ledger::create(['name' => 'Trade Sales', 'group_id' => $gid('Sales Accounts'), 'country' => 'India']);
                $purch = Ledger::create(['name' => 'Trade Purchases', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);

                $itemInvoice = fn (string $type, int $party, int $rev, string $pSide, string $lSide, float $qty, float $rate, string $date) => [
                    'client_uuid' => (string) Str::uuid(),
                    'payload' => [
                        'type' => $type, 'date' => $date, 'party_ledger_id' => $party,
                        'lines' => [
                            ['ledger_id' => $party, 'dr_cr' => $pSide, 'amount' => round($qty * $rate, 2)],
                            ['ledger_id' => $rev, 'dr_cr' => $lSide, 'amount' => round($qty * $rate, 2)],
                        ],
                        'items' => [['stock_item_id' => $widget->id, 'godown_id' => $godown, 'qty' => $qty, 'rate' => $rate]],
                    ],
                ];

                // ═══ TEST 1 — chronological push ═════════════════════════════
                // Batch entered offline in REVERSE date order: the Sale (05 Apr) is
                // FIRST in the array, the Purchase (03 Apr) SECOND. If posted in array
                // order the sale would cost at the opening-only 25; the sort must post
                // the purchase first so it costs at the weighted-average 40.
                $this->section('1. Chronological push (offline batch in reverse date order)');
                $sale = $itemInvoice('sales', $abc->id, $sales->id, 'Dr', 'Cr', 30, 90, '2026-04-05');
                $purchase = $itemInvoice('purchase', $modern->id, $purch->id, 'Cr', 'Dr', 60, 50, '2026-04-03');
                $push1 = $sync->push([$sale, $purchase]); // sale first!

                $byUuid = collect($push1['results'])->keyBy('client_uuid');
                $this->expect('both entries posted', collect($push1['results'])->where('status', 'posted')->count(), 2);
                $saleVoucherId = $byUuid[$sale['client_uuid']]['server_id'] ?? null;
                $se = StockEntry::where('voucher_id', $saleVoucherId)->first();
                $this->expect('sale posted (has a stock OUT row)', $se?->direction, 'out');
                $this->expectClose('sale COST rate = 40 (weighted-avg AFTER purchase — proves chronological order)', (float) $se->rate, 40);
                $this->expectClose('sale COST value = 1,200 (30 × 40, NOT 30 × 25 = 750)', (float) $se->value, 1200);
                $this->expectClose('sale SELL rate = 90 (unchanged)', (float) $se->sale_rate, 90);

                // ═══ TEST 2 — server-authoritative rejection ═════════════════
                $this->section('2. Server-authoritative rejection (one bad voucher in the batch)');
                $goodPayment = ['client_uuid' => (string) Str::uuid(), 'payload' => [
                    'type' => 'payment', 'date' => '2026-04-06',
                    'lines' => [
                        ['ledger_id' => $modern->id, 'dr_cr' => 'Dr', 'amount' => 500],
                        ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 500],
                    ],
                ]];
                $badJournal = ['client_uuid' => (string) Str::uuid(), 'payload' => [
                    'type' => 'journal', 'date' => '2026-04-06',
                    'lines' => [ // Dr 1000 ≠ Cr 900 — out of balance
                        ['ledger_id' => $purch->id, 'dr_cr' => 'Dr', 'amount' => 1000],
                        ['ledger_id' => $modern->id, 'dr_cr' => 'Cr', 'amount' => 900],
                    ],
                ]];
                $push2 = $sync->push([$goodPayment, $badJournal]);
                $r = collect($push2['results'])->keyBy('client_uuid');
                $this->expect('good payment posted', $r[$goodPayment['client_uuid']]['status'] ?? null, 'posted');
                $this->expect('bad journal REJECTED', $r[$badJournal['client_uuid']]['status'] ?? null, 'rejected');
                $this->expect('rejection carries its reason (balance)', str_contains(strtolower($r[$badJournal['client_uuid']]['reason'] ?? ''), 'balance'), true);
                $this->expect('rejected entry keeps its client_uuid (not lost)', ($r[$badJournal['client_uuid']]['client_uuid'] ?? null) === $badJournal['client_uuid'], true);
                $this->expect('the bad journal did NOT persist', Voucher::where('type', 'journal')->count(), 0);

                // ═══ TEST 3 — idempotency ════════════════════════════════════
                $this->section('3. Idempotency (a retried outbox drain never double-posts)');
                $beforeCount = Voucher::count();
                $push3 = $sync->push([$purchase]); // re-send an already-posted entry
                $this->expect('re-sent entry acknowledged as duplicate', $push3['results'][0]['duplicate'] ?? false, true);
                $this->expect('no new voucher created on re-send', Voucher::count(), $beforeCount);

                // ═══ TEST 4 — round-trip ═════════════════════════════════════
                $this->section('4. Round-trip (a synced voucher is a normal server voucher)');
                $v = Voucher::with('entries.ledger')->find($saleVoucherId);
                $this->expect('synced sale is in the Day Book with the same server id', $v?->toRow()['id'], $saleVoucherId);
                $this->expect('synced sale carries its client_uuid', $v?->client_uuid, $sale['client_uuid']);

                // ═══ TEST 5 — pull ═══════════════════════════════════════════
                $this->section('5. Pull (snapshot + incremental change-log)');
                $snap = $sync->pull(0);
                $this->expect('snapshot returns type=snapshot', $snap['type'], 'snapshot');
                $this->expect('snapshot includes the posted vouchers', count($snap['vouchers']) >= 3, true);
                $this->expect('snapshot includes masters (ledgers)', count($snap['masters']['ledgers']) > 0, true);
                $cursor = $sync->currentCursor();
                $incrEmpty = $sync->pull($cursor);
                $this->expect('incremental pull at the cursor returns nothing new', count($incrEmpty['vouchers']), 0);
                // make a new server-side change and confirm the pull surfaces it
                $push4 = $sync->push([$itemInvoice('purchase', $modern->id, $purch->id, 'Cr', 'Dr', 10, 55, '2026-04-07')]);
                $incr = $sync->pull($cursor);
                $this->expect('incremental pull returns the new voucher only', count($incr['vouchers']), 1);
                $this->expect('cursor advanced', $sync->currentCursor() > $cursor, true);
            });
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if (! $this->option('keep')) {
                $provisioner->teardown($slug);
            }
        }

        $this->line('');
        $this->info($this->ok ? 'ALL SYNC ASSERTIONS PASSED.' : 'SYNC ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function section(string $t): void
    {
        $this->line('');
        $this->line('── '.$t.' '.str_repeat('─', max(0, 58 - strlen($t))));
    }

    private function expect(string $label, $actual, $expected): void
    {
        $pass = $actual === $expected;
        $shown = is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) || $actual === null ? var_export($actual, true) : gettype($actual));
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.$shown);
        $this->ok = $this->ok && $pass;
    }

    private function expectClose(string $label, $actual, $expected): void
    {
        $pass = is_numeric($actual) && abs((float) $actual - (float) $expected) < 0.005;
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label.' = '.var_export($actual, true));
        $this->ok = $this->ok && $pass;
    }
}
