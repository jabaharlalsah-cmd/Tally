<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tenant\DataPrivacyController;
use App\Livewire\VoucherScreen;
use App\Models\Company;
use App\Models\Ledger;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\TenantExport;
use App\Models\TenantLifecycleEvent;
use App\Models\TenantUser;
use App\Notifications\BackupFailed;
use App\Notifications\OffboardingInitiated;
use App\Notifications\TenantPurged;
use App\Services\Backups\BackupService;
use App\Services\Exports\ExportService;
use App\Services\Offboarding\OffboardingService;
use App\Services\Subscription\SubscriptionService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Phase 14C — THE backups / export / offboarding proof.
 *
 * Provisions throwaway tenants, populates a small deterministic dataset, and walks every
 * rule: verified backups, retention, restore-into-a-FRESH-DB (never overwrite), integrity
 * failure, a complete customer export (byte-checked for omissions), the offboarding state
 * machine (initiate → archived → purge_scheduled → purged, reversible until purge), and the
 * legal retention of the central row + payment history after purge.
 */
class ProveBackupsExportsOffboardingCommand extends Command
{
    protected $signature = 'zerobook:prove-backups-exports-offboarding {--keep : keep the throwaway tenants + files}';

    protected $description = 'Prove Phase 14C: verified per-tenant backups, retention, restore-and-verify, integrity failure, complete data export, and the full offboarding lifecycle (reversible until purge).';

    private bool $ok = true;

    /** bko = backups, exp = export, offa/offb/offc = offboarding paths. */
    private array $slugs = ['bko', 'exp', 'offa', 'offb', 'offc'];

    private string $adminEmail = 'c14cops@zerobook.test';

    private PlatformAdmin $admin;

    private Plan $pro;

    public function handle(
        TenantProvisioner $provisioner,
        BackupService $backups,
        ExportService $exports,
        OffboardingService $offboarding,
        SubscriptionService $subscriptions,
    ): int {
        Notification::fake();

        try {
            $this->cleanup($provisioner);
            $this->admin = PlatformAdmin::updateOrCreate(['email' => $this->adminEmail], ['name' => '14C Ops', 'password' => Hash::make('x')]);
            $this->pro = Plan::where('tier', 'professional-monthly')->firstOrFail();

            foreach ($this->slugs as $slug) {
                $provisioner->provision($slug, ucfirst($slug).' Co', 'trial', 'india');
                TenantUser::updateOrCreate(['tenant_id' => $slug, 'email' => "owner@{$slug}.test"], [
                    'name' => 'Owner', 'password' => Hash::make('x'), 'role' => 'owner', 'verified_at' => now(),
                ]);
            }

            $this->section('1 · A backup is dumped, VERIFIED, gzipped, and recorded');
            $this->backupHappyPath($backups);

            $this->section('2 · Retention: monthly > weekly > daily, and the prune deletes only the expired');
            $this->retention($backups);

            $this->section('3 · Restore lands in a FRESH database — original untouched, counts + balance verified');
            $this->restoreAndVerify($backups, $provisioner);

            $this->section('4 · A corrupt archive is rejected — no row kept, admins alerted');
            $this->integrityFailure($backups);

            $this->section('5 · Export is a COMPLETE, byte-checked archive of the books');
            $this->exportCompleteness($exports);

            $this->section('6 · Export: signed 7-day link + one-per-24h rate limit');
            $this->exportLinkAndRateLimit($exports);

            $this->section('7 · Offboarding initiate → suspended, safety backup + export, email, typed-confirm guard');
            $this->offboardInitiate($offboarding);

            $this->section('8 · Reactivate from suspended(offboarding) → active + writable again');
            $this->reactivateFromSuspended($offboarding);

            $this->section('9 · Auto-advance to archived, then reactivate → pre-offboarding snapshot restored in place');
            $this->archiveThenReactivate($offboarding, $backups);

            $this->section('10 · Advance archived → purge_scheduled → purged (DB dropped, files gone, central row + payments KEPT)');
            $this->advanceToPurge($offboarding, $subscriptions);

            $this->section('11 · Purge is the point of no return — reactivation refused');
            $this->purgeIrreversible($offboarding);

            $this->section('12 · Every transition left a lifecycle audit trail');
            $this->auditTrail();
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if (! $this->option('keep')) {
                $this->cleanup($provisioner);
            }
        }

        $this->line('');
        if ($this->ok) {
            $this->info('ALL ASSERTIONS PASSED — Phase 14C backups / export / offboarding verified.');

            return self::SUCCESS;
        }
        $this->error('SOME ASSERTIONS FAILED.');

        return self::FAILURE;
    }

    // ── 1. backup happy path ────────────────────────────────────────────────────

    private function backupHappyPath(BackupService $backups): void
    {
        $this->seedDataset('bko');
        $counts = $this->dbCounts('bko');

        $b = $backups->backupTenant(Tenant::find('bko'), 'scheduled');
        $this->expect('backup file written', Storage::disk('local')->exists($b->file_path), true);
        $this->expect('stored as .sql.gz', str_ends_with($b->file_path, '.sql.gz'), true);
        $this->expect('non-empty archive', $b->file_size_bytes > 0, true);
        $this->expect('verified_at stamped (integrity checked)', $b->verified_at !== null, true);
        $this->expect('meta recorded voucher count', (int) ($b->meta['vouchers'] ?? -1), $counts['vouchers']);

        // The archive really decompresses to SQL (the gunzip -t equivalent).
        $sql = @gzdecode(Storage::disk('local')->get($b->file_path));
        $this->expect('archive decompresses to a real dump', is_string($sql) && str_contains($sql, 'CREATE TABLE'), true);

        // On-demand admin trigger produces a second, distinct backup.
        $b2 = $backups->backupTenant(Tenant::find('bko'), 'on_demand');
        $this->expect('on-demand backup recorded', $b2->type, 'on_demand');
        $this->expect('two distinct backup files', $b->file_path !== $b2->file_path, true);
    }

    // ── 2. retention ────────────────────────────────────────────────────────────

    private function retention(BackupService $backups): void
    {
        $t = Tenant::find('bko');
        $r = (array) config('zerobook.backup_retention.default');
        $this->expect('retention policy = 7 daily / 4 weekly / 12 monthly', [$r['daily_days'], $r['weekly_weeks'], $r['monthly_months']], [7, 4, 12]);

        // expiryFor picks the longest applicable window.
        $monthly = $backups->expiryFor($t, Carbon::parse('2026-06-01')); // 1st of month
        $weekly = $backups->expiryFor($t, Carbon::parse('2026-06-07'));  // a Sunday
        $daily = $backups->expiryFor($t, Carbon::parse('2026-06-09'));   // a plain Tuesday
        $this->expect('1st-of-month backup kept 12 months', $monthly->toDateString(), '2027-06-01');
        $this->expect('Sunday backup kept 4 weeks', $weekly->toDateString(), '2026-07-05');
        $this->expect('weekday backup kept 7 days', $daily->toDateString(), '2026-06-16');
        $this->expect('monthly outlives weekly outlives daily', $monthly->gt($weekly) && $weekly->gt($daily), true);

        // Seed 15 already-expired + 5 still-valid rows; the prune removes exactly the 15.
        for ($i = 0; $i < 15; $i++) {
            $this->fakeBackupRow('bko', now()->subDays(40 + $i), now()->subDays(1));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->fakeBackupRow('bko', now()->subDays($i), now()->addDays(30));
        }
        $before = TenantBackup::where('tenant_id', 'bko')->count();
        $pruned = $backups->pruneBackups($t);
        $after = TenantBackup::where('tenant_id', 'bko')->count();

        $this->expect('prune deleted exactly the 15 expired', $pruned, 15);
        $this->expect('all still-valid backups survived', $before - $after, 15);
        $this->expect('no backup with a past expiry remains', TenantBackup::where('tenant_id', 'bko')->where('expires_at', '<', now())->count(), 0);
    }

    // ── 3. restore-and-verify ───────────────────────────────────────────────────

    private function restoreAndVerify(BackupService $backups, TenantProvisioner $provisioner): void
    {
        $original = Tenant::find('bko');
        $origDb = $original->database()->getName();
        $origCounts = $this->dbCounts('bko');

        $backup = $backups->backupTenant($original, 'on_demand');
        $restored = $backups->restoreTenant($original, $backup);

        try {
            $this->expect('restored into a DIFFERENT database', $restored->database()->getName() !== $origDb, true);
            $this->expect('restored tenant marked "restored"', $restored->status, 'restored');

            $restoredCounts = $restored->run(fn () => [
                'vouchers' => (int) \DB::table('vouchers')->count(),
                'voucher_entries' => (int) \DB::table('voucher_entries')->count(),
                'ledgers' => (int) \DB::table('ledgers')->count(),
            ]);
            $this->expect('restored voucher count matches original', $restoredCounts['vouchers'], $origCounts['vouchers']);
            $this->expect('restored ledger count matches original', $restoredCounts['ledgers'], $origCounts['ledgers']);

            // Double-entry still balances in the restored copy (prove-balance equivalent).
            $bal = $restored->run(function () {
                $dr = (int) \DB::table('voucher_entries')->where('dr_cr', 'Dr')->sum('amount');
                $cr = (int) \DB::table('voucher_entries')->where('dr_cr', 'Cr')->sum('amount');

                return [$dr, $cr];
            });
            $this->expect('restored data balances (Dr === Cr)', $bal[0] === $bal[1] && $bal[0] > 0, true);

            // The ORIGINAL database is untouched — same name, same data, still present.
            $this->expect('original DB still exists', $this->databaseExists($origDb), true);
            $this->expect('original counts unchanged', $this->dbCounts('bko'), $origCounts);
        } finally {
            $provisioner->teardown($restored->id); // drop the throwaway restore target
        }
    }

    // ── 4. integrity failure ────────────────────────────────────────────────────

    private function integrityFailure(BackupService $backups): void
    {
        $rowsBefore = TenantBackup::where('tenant_id', 'bko')->count();

        $threw = false;
        try {
            // Corrupt the freshly-written archive before verification runs.
            $backups->backupTenant(Tenant::find('bko'), 'scheduled', function (string $absPath) {
                file_put_contents($absPath, 'this is not a gzip archive at all');
            });
        } catch (RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'integrity');
        }

        $this->expect('corrupt backup throws an integrity error', $threw, true);
        $this->expect('NO backup row was recorded for the corrupt archive', TenantBackup::where('tenant_id', 'bko')->count(), $rowsBefore);
        Notification::assertSentTo($this->admin, BackupFailed::class);
        $this->line('   [PASS] platform admins alerted to the failed backup');
    }

    // ── 5. export completeness ──────────────────────────────────────────────────

    private function exportCompleteness(ExportService $exports): void
    {
        $this->seedDataset('exp');
        $counts = $this->dbCounts('exp');

        $export = $exports->exportTenant(Tenant::find('exp'), $this->owner('exp'));
        $this->expect('export completed (sync job ran inline)', $export->status, 'completed');
        $this->expect('zip stored on the private disk', Storage::disk('local')->exists($export->file_path), true);
        $this->expect('zip is under exports/{tenant}/', str_starts_with($export->file_path, 'exports/exp/'), true);

        $zip = new ZipArchive();
        $zip->open(Storage::disk('local')->path($export->file_path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->statIndex($i)['name'];
        }

        // Required top-level members.
        $this->expect('README.txt present', in_array('README.txt', $names, true), true);
        $this->expect('full mysqldump present (database.sql.gz)', in_array('database.sql.gz', $names, true), true);
        $this->expect('the dump is valid gzip SQL', str_contains((string) @gzdecode($zip->getFromName('database.sql.gz')), 'CREATE TABLE'), true);

        // Byte-for-byte completeness: each CSV holds exactly the DB's rows (no silent omission).
        $ledgerCsv = $this->firstEnding($names, '/ledgers.csv');
        $veCsv = $this->firstEnding($names, '/voucher_entries.csv');
        $seCsv = $this->firstEnding($names, '/stock_entries.csv');
        $this->expect('ledgers.csv exists', $ledgerCsv !== null, true);
        $this->expect("ledgers.csv has all {$counts['ledgers']} ledgers", $this->rows($zip, $ledgerCsv), $counts['ledgers']);
        $this->expect("voucher_entries.csv has all {$counts['voucher_entries']} entries", $this->rows($zip, $veCsv), $counts['voucher_entries']);
        $this->expect("stock_entries.csv has all {$counts['stock_entries']} movements", $this->rows($zip, $seCsv), $counts['stock_entries']);

        // Vouchers are split one CSV per type — their sum equals the DB voucher count.
        $voucherRows = 0;
        foreach ($names as $n) {
            if (preg_match('#/vouchers_[^/]+\.csv$#', $n)) {
                $voucherRows += $this->rows($zip, $n);
            }
        }
        $this->expect("vouchers_*.csv together hold all {$counts['vouchers']} vouchers", $voucherRows, $counts['vouchers']);

        // Authoritative report snapshots are included.
        $this->expect('Trial Balance snapshot present', $this->firstEnding($names, '/reports/Trial Balance.csv') !== null, true);
        $this->expect('Balance Sheet snapshot present', $this->firstEnding($names, '/reports/Balance Sheet.csv') !== null, true);
        $this->expect('Profit and Loss snapshot present', $this->firstEnding($names, '/reports/Profit and Loss.csv') !== null, true);
        $zip->close();
    }

    // ── 6. export link + rate limit ─────────────────────────────────────────────

    private function exportLinkAndRateLimit(ExportService $exports): void
    {
        $export = TenantExport::where('tenant_id', 'exp')->where('status', 'completed')->latest('id')->firstOrFail();

        $this->expect('completed export is downloadable', $export->isDownloadable(), true);
        $this->expect('link expiry ≈ 7 days out', $export->download_expires_at->between(now()->addDays(6), now()->addDays(8)), true);

        $url = $exports->signedDownloadUrl($export);
        $this->expect('download URL is signed', str_contains($url, 'signature='), true);
        $this->expect('download URL targets this export', str_contains($url, '/account/data/export/'.$export->id.'/'), true);

        // Second export within 24h is refused.
        $this->expect('a second export within 24h is rate-limited', $exports->canExport(Tenant::find('exp')), false);
        $controller = new DataPrivacyController();
        $blocked = Tenant::find('exp')->run(function () use ($controller, $exports) {
            $resp = $controller->export(new Request(), $exports);

            return $resp->getSession()?->get('errors')?->has('export') ?? false;
        });
        $this->expect('the export screen surfaces the rate-limit error', $blocked, true);
    }

    // ── 7. offboarding: initiate ────────────────────────────────────────────────

    private function offboardInitiate(OffboardingService $offboarding): void
    {
        $this->seedDataset('offa');

        // Typed-confirmation guard: a wrong subdomain is rejected before anything happens.
        $controller = new DataPrivacyController();
        $rejected = Tenant::find('offa')->run(function () use ($controller, $offboarding) {
            $req = Request::create('/account/close', 'POST', ['confirm' => 'WRONG', 'reason' => 'x']);
            $resp = $controller->close($req, $offboarding);

            return $resp->getSession()?->get('errors')?->has('confirm') ?? false;
        });
        $this->expect('wrong typed-confirmation is rejected (no offboarding)', $rejected, true);
        $this->expect('tenant still active after a rejected confirmation', Tenant::find('offa')->status, 'active');

        // Real initiation.
        $offboarding->initiate(Tenant::find('offa'), $this->owner('offa'), 'Closing shop.');
        $t = Tenant::find('offa');

        $this->expect('status → suspended', $t->status, 'suspended');
        $this->expect('isOffboarding() true', $t->isOffboarding(), true);
        $this->expect('offboarding_initiated_at set', $t->offboarding_initiated_at !== null, true);
        $this->expect('archive scheduled +30d', $t->archive_scheduled_for?->toDateString(), now()->addDays(30)->toDateString());
        $this->expect('purge scheduled +150d', $t->purge_scheduled_for?->toDateString(), now()->addDays(150)->toDateString());
        $this->expect('a pre_offboarding safety backup was taken', TenantBackup::where('tenant_id', 'offa')->where('type', 'pre_offboarding')->exists(), true);
        $this->expect('a data export was generated at closure', TenantExport::where('tenant_id', 'offa')->exists(), true);
        Notification::assertSentTo($this->owner('offa'), OffboardingInitiated::class);
        $this->line('   [PASS] owner emailed the closure confirmation + timeline');

        // Initiating again is refused (already offboarding).
        $again = false;
        try {
            $offboarding->initiate(Tenant::find('offa'), $this->owner('offa'));
        } catch (Throwable) {
            $again = true;
        }
        $this->expect('cannot initiate offboarding twice', $again, true);
    }

    // ── 8. reactivate from suspended(offboarding) ───────────────────────────────

    private function reactivateFromSuspended(OffboardingService $offboarding): void
    {
        // The legacy 14A plain-reactivate must REFUSE an offboarding tenant — it would flip the
        // status to active but strand archive_scheduled_for / purge_scheduled_for. Only
        // OffboardingService::reactivate (used below) is allowed to bring it back.
        $plain = app(\App\Http\Controllers\Central\PlatformController::class);
        try {
            $req = Request::create('/admin/tenants/offa/reactivate', 'POST');
            $req->setLaravelSession(app('session.store'));
            $plain->reactivate($req, Tenant::find('offa'), app(\App\Services\Platform\PlatformActions::class));
        } catch (Throwable) {
            // back()->withErrors without a full request cycle may throw — the point is the guard
            // returns BEFORE mutating, so the tenant must be unchanged either way.
        }
        $stillOff = Tenant::find('offa');
        $this->expect('14A plain-reactivate REFUSES an offboarding tenant (unchanged)', [$stillOff->status, $stillOff->isOffboarding()], ['suspended', true]);

        $offboarding->reactivate(Tenant::find('offa'), $this->admin);
        $t = Tenant::find('offa');

        $this->expect('status → active', $t->status, 'active');
        $this->expect('offboarding flags cleared', [$t->offboarding_initiated_at, $t->archive_scheduled_for, $t->purge_scheduled_for], [null, null, null]);
        $this->expect('not offboarding anymore', $t->isOffboarding(), false);

        // Writable again: a balanced voucher posts without the tenant write-block.
        $posted = $t->run(fn () => ActiveCompany::runAs(Company::defaultCompany()->id, function () {
            (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-07-13', 'lines' => [
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 5],
                ['ledger_id' => $this->anyExpenseLedgerId(), 'dr_cr' => 'Dr', 'amount' => 5],
            ]]);

            return true;
        }));
        $this->expect('reactivated tenant can post again', $posted, true);
    }

    // ── 9. archive → reactivate (restore in place) ──────────────────────────────

    private function archiveThenReactivate(OffboardingService $offboarding, BackupService $backups): void
    {
        $this->seedDataset('offb');
        $offboarding->initiate(Tenant::find('offb'), $this->owner('offb'));
        $snapshotLedgers = $this->dbCounts('offb')['ledgers'];

        // Fast-forward past the 30-day archive window, then advance one step.
        Tenant::find('offb')->forceFill(['offboarding_initiated_at' => now()->subDays(31)])->save();
        $offboarding->advance(Tenant::find('offb'));
        $this->expect('auto-advanced to archived', Tenant::find('offb')->status, 'archived');
        $this->expect('archived tenant is access-closed', Tenant::find('offb')->isClosed(), true);

        // Mutate the live DB AFTER the snapshot so the restore has something to discard.
        Tenant::find('offb')->run(fn () => ActiveCompany::runAs(Company::defaultCompany()->id, fn () => Ledger::create([
            'name' => 'Ghost Ledger (post-snapshot)',
            'group_id' => \App\Models\AccountGroup::where('name', 'Indirect Expenses')->value('id'),
            'country' => 'India',
        ])));
        $this->expect('a ledger was added to the archived DB', $this->dbCounts('offb')['ledgers'], $snapshotLedgers + 1);

        // Reactivate → restores the pre-offboarding snapshot IN PLACE (same subdomain).
        $offboarding->reactivate(Tenant::find('offb'), $this->admin);
        $t = Tenant::find('offb');
        $this->expect('status → active', $t->status, 'active');
        $this->expect('pre-offboarding snapshot restored in place (post-snapshot ledger discarded)', $this->dbCounts('offb')['ledgers'], $snapshotLedgers);
        $this->expect('restored-in-place DB still balances', $this->balanced('offb'), true);
    }

    // ── 10. advance to purge ────────────────────────────────────────────────────

    private function advanceToPurge(OffboardingService $offboarding, SubscriptionService $subscriptions): void
    {
        $this->seedDataset('offc');

        // A confirmed payment BEFORE closure — its record must survive the purge (legal).
        $subscriptions->recordPayment([
            'tenant_id' => 'offc', 'plan_id' => $this->pro->id, 'amount' => 1499, 'currency' => 'INR',
            'payment_mode' => 'upi', 'reference_number' => 'KEEP-ME', 'received_at' => '2026-01-01',
            'status' => 'confirmed', 'recorded_by_admin_id' => $this->admin->id,
        ], $this->admin);
        $paymentId = Payment::where('tenant_id', 'offc')->value('id');

        $offboarding->initiate(Tenant::find('offc'), $this->owner('offc'));
        $db = Tenant::find('offc')->database()->getName();

        // Step to archived.
        Tenant::find('offc')->forceFill(['offboarding_initiated_at' => now()->subDays(31)])->save();
        $offboarding->advance(Tenant::find('offc'));
        $this->expect('advanced to archived', Tenant::find('offc')->status, 'archived');

        // Step to purge_scheduled (archive_days + purge_schedule_days = 120).
        Tenant::find('offc')->forceFill(['offboarding_initiated_at' => now()->subDays(121)])->save();
        $offboarding->advance(Tenant::find('offc'));
        $this->expect('advanced to purge_scheduled', Tenant::find('offc')->status, 'purge_scheduled');

        // Step to purged (… + purge_days = 150).
        Tenant::find('offc')->forceFill(['offboarding_initiated_at' => now()->subDays(151)])->save();
        $offboarding->advance(Tenant::find('offc'));
        $t = Tenant::find('offc');

        $this->expect('advanced to purged', $t->status, 'purged');
        $this->expect('tenant database dropped', $this->databaseExists($db), false);
        $this->expect('all backup files + rows deleted', TenantBackup::where('tenant_id', 'offc')->count(), 0);
        $this->expect('all export rows deleted', TenantExport::where('tenant_id', 'offc')->count(), 0);
        $this->expect('subdomain no longer resolves (domains gone)', $t->domains()->count(), 0);

        // The legally-retained bits: the central row + the payment history REMAIN.
        $this->expect('central tenants row KEPT (not deleted)', Tenant::whereKey('offc')->exists(), true);
        $this->expect('payment history KEPT after purge', Payment::whereKey($paymentId)->exists(), true);
        Notification::assertSentTo($this->owner('offc'), TenantPurged::class);
        $this->line('   [PASS] owner notified the account was purged');
    }

    // ── 11. purge irreversible ──────────────────────────────────────────────────

    private function purgeIrreversible(OffboardingService $offboarding): void
    {
        $refused = false;
        $msg = '';
        try {
            $offboarding->reactivate(Tenant::find('offc'), $this->admin);
        } catch (Throwable $e) {
            $refused = true;
            $msg = $e->getMessage();
        }
        $this->expect('reactivating a purged tenant is refused', $refused, true);
        $this->expect('refusal explains data is permanently gone', str_contains($msg, 'permanently'), true);
    }

    // ── 12. audit trail ─────────────────────────────────────────────────────────

    private function auditTrail(): void
    {
        $this->expectHas('offa', ['offboarding_initiated', 'reactivated'], 'user-triggered close + admin reactivate logged');
        $this->expectHas('offb', ['offboarding_initiated', 'archived', 'reactivated'], 'archive + reactivate logged');
        $this->expectHas('offc', ['offboarding_initiated', 'archived', 'purge_scheduled', 'purged'], 'full purge chain logged');
        $this->expectHas('bko', ['backup_taken', 'restored'], 'backup + restore logged');
        $this->expectHas('exp', ['export_generated'], 'export logged');

        // The user-triggered close records WHO (a tenant user), not an admin.
        $init = TenantLifecycleEvent::where('tenant_id', 'offa')->where('event', 'offboarding_initiated')->latest('id')->first();
        $this->expect('close event attributes the tenant user', $init?->triggered_by_user_id !== null && $init?->triggered_by_admin_id === null, true);
        $react = TenantLifecycleEvent::where('tenant_id', 'offa')->where('event', 'reactivated')->latest('id')->first();
        $this->expect('reactivate event attributes the platform admin', $react?->triggered_by_admin_id === $this->admin->id, true);
    }

    // ── dataset + helpers ───────────────────────────────────────────────────────

    /** A small, deterministic, BALANCED dataset: 3 vouchers, 6 entries, 1 stock movement. */
    private function seedDataset(string $slug): void
    {
        Tenant::find($slug)->run(function () {
            ActiveCompany::runAs(Company::defaultCompany()->id, function () {
                $gid = fn (string $n) => \App\Models\AccountGroup::where('name', $n)->value('id');

                // Inventory + party + purchase masters.
                $unit = \App\Models\Unit::create(['name' => 'Numbers', 'symbol' => 'Nos', 'decimal_places' => 0]);
                $grp = \App\Models\StockGroup::create(['name' => 'Goods']);
                $item = \App\Models\StockItem::create([
                    'name' => 'Widget', 'stock_group_id' => $grp->id, 'unit_id' => $unit->id,
                    'opening_qty' => 0, 'opening_rate' => 0, 'opening_value' => 0,
                    'gst_rate' => 0, 'costing_method' => 'weighted_average',
                ]);
                $godownId = \App\Models\Godown::where('name', 'Main Location')->value('id')
                    ?? \App\Models\Godown::create(['name' => 'Main Location', 'is_reserved' => true])->id;

                $expense = Ledger::create(['name' => 'Office Expenses', 'group_id' => $gid('Indirect Expenses'), 'country' => 'India']);
                $debtor = Ledger::create(['name' => 'A Customer', 'group_id' => $gid('Sundry Debtors'), 'country' => 'India']);
                $supplier = Ledger::create(['name' => 'A Supplier', 'group_id' => $gid('Sundry Creditors'), 'country' => 'India']);
                $purchase = Ledger::create(['name' => 'Purchases', 'group_id' => $gid('Purchase Accounts'), 'country' => 'India']);
                $cashId = Ledger::where('name', 'Cash')->value('id');

                // GST off — keep the invoice free of tax legs so the dataset stays exact.
                \App\Models\CompanyFeature::current()->update(['gst' => false, 'vat' => false]);

                $screen = new VoucherScreen();

                // 1) Payment: expense paid in cash.
                $screen->post(['type' => 'payment', 'date' => '2026-07-01', 'lines' => [
                    ['ledger_id' => $expense->id, 'dr_cr' => 'Dr', 'amount' => 500],
                    ['ledger_id' => $cashId, 'dr_cr' => 'Cr', 'amount' => 500],
                ]]);

                // 2) Receipt: cash from a debtor.
                $screen->post(['type' => 'receipt', 'date' => '2026-07-02', 'lines' => [
                    ['ledger_id' => $cashId, 'dr_cr' => 'Dr', 'amount' => 300],
                    ['ledger_id' => $debtor->id, 'dr_cr' => 'Cr', 'amount' => 300],
                ]]);

                // 3) Purchase invoice with a stock item → also writes a stock_entry.
                $screen->post([
                    'type' => 'purchase', 'date' => '2026-07-03', 'party_ledger_id' => $supplier->id,
                    'reference_no' => 'PINV-1',
                    'lines' => [
                        ['ledger_id' => $supplier->id, 'dr_cr' => 'Cr', 'amount' => 1000],
                        ['ledger_id' => $purchase->id, 'dr_cr' => 'Dr', 'amount' => 1000],
                    ],
                    'items' => [['stock_item_id' => $item->id, 'godown_id' => $godownId, 'qty' => 10, 'rate' => 100]],
                ]);
            });
        });
    }

    private function dbCounts(string $slug): array
    {
        return Tenant::find($slug)->run(fn () => [
            'vouchers' => (int) \DB::table('vouchers')->count(),
            'voucher_entries' => (int) \DB::table('voucher_entries')->count(),
            'ledgers' => (int) \DB::table('ledgers')->count(),
            'stock_entries' => (int) \DB::table('stock_entries')->count(),
        ]);
    }

    private function balanced(string $slug): bool
    {
        return Tenant::find($slug)->run(function () {
            $dr = (int) \DB::table('voucher_entries')->where('dr_cr', 'Dr')->sum('amount');
            $cr = (int) \DB::table('voucher_entries')->where('dr_cr', 'Cr')->sum('amount');

            return $dr === $cr && $dr > 0;
        });
    }

    private function anyExpenseLedgerId(): int
    {
        return (int) Ledger::where('name', 'Office Expenses')->value('id');
    }

    private function fakeBackupRow(string $slug, Carbon $takenAt, Carbon $expiresAt): void
    {
        $path = "backups/{$slug}/fake-".$takenAt->format('Ymd-His-u').'.sql.gz';
        Storage::disk('local')->put($path, gzencode("CREATE TABLE x(id int);\n", 6));
        TenantBackup::create([
            'tenant_id' => $slug, 'type' => 'scheduled', 'file_path' => $path,
            'file_size_bytes' => Storage::disk('local')->size($path),
            'meta' => ['vouchers' => 0, 'voucher_entries' => 0, 'ledgers' => 0],
            'taken_at' => $takenAt, 'verified_at' => $takenAt, 'expires_at' => $expiresAt,
        ]);
    }

    /** Number of DATA rows (excluding the header) in a zipped CSV. */
    private function rows(ZipArchive $zip, ?string $name): int
    {
        if ($name === null) {
            return -1;
        }
        $content = $zip->getFromName($name);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $n = 0;
        while (fgetcsv($fh) !== false) {
            $n++;
        }
        fclose($fh);

        return max(0, $n - 1); // minus header
    }

    private function firstEnding(array $names, string $suffix): ?string
    {
        foreach ($names as $n) {
            if (str_ends_with($n, $suffix)) {
                return $n;
            }
        }

        return null;
    }

    private function databaseExists(string $db): bool
    {
        $central = config('tenancy.database.central_connection');

        return \DB::connection($central)->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]
        ) !== null;
    }

    private function owner(string $slug): TenantUser
    {
        return TenantUser::where('tenant_id', $slug)->where('role', 'owner')->first();
    }

    private function expectHas(string $slug, array $events, string $label): void
    {
        $have = TenantLifecycleEvent::where('tenant_id', $slug)->pluck('event')->all();
        $missing = array_diff($events, $have);
        $this->expect($label, $missing === [] ? 'all present' : 'missing '.implode(',', $missing), 'all present');
    }

    private function cleanup(TenantProvisioner $provisioner): void
    {
        // Any leftover restore targets from a prior aborted run.
        foreach (Tenant::where('id', 'like', 'bkorst%')->orWhere('id', 'like', 'exprst%')->get() as $t) {
            $provisioner->teardown($t->id);
        }
        foreach ($this->slugs as $slug) {
            $provisioner->teardown($slug);
            Storage::disk('local')->deleteDirectory("backups/{$slug}");
            Storage::disk('local')->deleteDirectory("exports/{$slug}");
        }
        PlatformAdmin::where('email', $this->adminEmail)->delete();
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ".str_repeat('─', max(1, 72 - mb_strlen($title))));
    }

    private function expect(string $label, mixed $actual, mixed $expected): void
    {
        $pass = $actual === $expected;
        if (! $pass) {
            $this->ok = false;
        }
        $this->line(sprintf('   [%s] %s = %s%s', $pass ? 'PASS' : 'FAIL', $label, json_encode($actual), $pass ? '' : ' (expected '.json_encode($expected).')'));
    }
}
