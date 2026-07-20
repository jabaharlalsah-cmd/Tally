<?php

namespace App\Services\Backups;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\TenantLifecycleEvent;
use App\Notifications\BackupFailed;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Phase 14C — per-tenant MySQL backups: dump → gzip → VERIFY → record, plus a restore
 * that always lands in a FRESH database, and a retention prune.
 *
 * A backup is only recorded once its `.sql.gz` decompresses cleanly (the gunzip -t
 * equivalent, done in PHP) — an unverified backup is worse than none, so a failed
 * integrity check deletes the file, alerts the platform admins, and records NO row.
 */
class BackupService
{
    /** Run (and verify + record) a backup of a tenant's database. */
    public function backupTenant(Tenant $tenant, string $type = 'scheduled', ?callable $mutateForTest = null): TenantBackup
    {
        $takenAt = now();
        $db = $tenant->database()->getName();

        $sql = $this->dumpDatabase($db);
        $counts = $this->counts($tenant);

        // A short random suffix keeps two backups taken in the same second (e.g. a manual
        // "backup now" right after the scheduled run) from colliding on the same filename.
        $path = "backups/{$tenant->id}/".$takenAt->format('Y-m-d-His').'-'.Str::lower(Str::random(6)).'.sql.gz';
        Storage::disk('local')->put($path, gzencode($sql, 6));

        // Test-only hook to corrupt the written file so the integrity path can be proven.
        if ($mutateForTest !== null) {
            $mutateForTest(Storage::disk('local')->path($path));
        }

        if (! $this->verifyGzip($path)) {
            Storage::disk('local')->delete($path);
            $this->alertBackupFailed($tenant, 'Backup integrity check failed (corrupt archive).');

            throw new RuntimeException("Backup integrity check failed for tenant [{$tenant->id}] — file deleted, no record kept.");
        }

        $backup = TenantBackup::create([
            'tenant_id' => $tenant->id,
            'type' => $type,
            'file_path' => $path,
            'file_size_bytes' => Storage::disk('local')->size($path),
            'meta' => $counts,
            'taken_at' => $takenAt,
            'verified_at' => now(),
            'expires_at' => $this->expiryFor($tenant, $takenAt),
        ]);

        $this->lifecycle($tenant, 'backup_taken', "type={$type} backup #{$backup->id} ({$backup->humanSize()})");

        return $backup;
    }

    /**
     * Restore a backup into a NEW tenant database (never overwrite the original). Returns
     * the fresh Tenant (status 'restored'). Verifies structure, voucher counts, and the
     * double-entry balance; on any failure the fresh DB is dropped and the row removed.
     */
    public function restoreTenant(Tenant $original, TenantBackup $backup): Tenant
    {
        $sql = @gzdecode(Storage::disk('local')->get($backup->file_path));
        if ($sql === false || $sql === '') {
            throw new RuntimeException("Backup #{$backup->id} could not be decompressed — restore aborted.");
        }

        $restored = Tenant::create([
            'id' => $this->restoredSlug($original->id),
            'name' => $original->name.' (restored '.now()->format('d-M H:i').')',
            'plan_id' => $original->plan_id,
            'status' => 'restored',
        ]);

        dispatch_sync(new CreateDatabase($restored));

        try {
            $this->import($restored->database()->getName(), $sql);
            $this->verifyDatabase($restored->database()->getName(), $backup);
        } catch (Throwable $e) {
            // Abandon: drop the fresh DB and delete the central row — original untouched.
            if ($this->databaseExists($restored->database()->getName())) {
                dispatch_sync(new DeleteDatabase($restored));
            }
            $restored->delete();

            throw new RuntimeException("Restore verification failed for [{$original->id}] from backup #{$backup->id}: ".$e->getMessage());
        }

        $this->lifecycle($original, 'restored', "restored into [{$restored->id}] from backup #{$backup->id}");

        return $restored->refresh();
    }

    /**
     * Phase 14C reactivation — restore a backup INTO the tenant's own database, replacing
     * it. Distinct from restoreTenant() (which never overwrites): reactivation from an
     * archived state deliberately brings the original subdomain back to the pre-offboarding
     * snapshot. Verified in a throwaway DB first, so the original is only dropped once the
     * backup is proven restorable.
     */
    public function restoreInPlace(Tenant $tenant, TenantBackup $backup): void
    {
        $sql = @gzdecode(Storage::disk('local')->get($backup->file_path));
        if ($sql === false || $sql === '') {
            throw new RuntimeException("Backup #{$backup->id} could not be decompressed — reactivation aborted.");
        }

        $central = config('tenancy.database.central_connection');
        $db = $tenant->database()->getName();
        $verifyDb = $db.'_reactv'.now()->format('His');

        // 1) Prove it restores + balances in a throwaway DB before touching the original.
        DB::connection($central)->statement("CREATE DATABASE `{$verifyDb}` CHARACTER SET utf8mb4");
        try {
            $this->import($verifyDb, $sql);
            $this->verifyDatabase($verifyDb, $backup);
        } catch (Throwable $e) {
            DB::connection($central)->statement("DROP DATABASE IF EXISTS `{$verifyDb}`");

            throw new RuntimeException('Reactivation aborted — the pre-offboarding backup did not verify: '.$e->getMessage());
        }
        DB::connection($central)->statement("DROP DATABASE IF EXISTS `{$verifyDb}`");

        // 2) Recreate the original database from the verified backup.
        DB::connection($central)->statement("DROP DATABASE IF EXISTS `{$db}`");
        DB::connection($central)->statement("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
        $this->import($db, $sql);
    }

    /** Delete backups (file + row) past their retention window. Returns the count deleted. */
    public function pruneBackups(Tenant $tenant): int
    {
        $expired = TenantBackup::where('tenant_id', $tenant->id)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $backup) {
            Storage::disk('local')->delete($backup->file_path);
            $backup->delete();
        }

        return $expired->count();
    }

    /** The longest retention this backup qualifies for (monthly > weekly > daily). */
    public function expiryFor(Tenant $tenant, Carbon $takenAt): Carbon
    {
        $r = $this->retention($tenant);

        if ($takenAt->day === 1) {
            return $takenAt->copy()->addMonths($r['monthly_months']);
        }
        if ($takenAt->isSunday()) {
            return $takenAt->copy()->addWeeks($r['weekly_weeks']);
        }

        return $takenAt->copy()->addDays($r['daily_days']);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Raw mysqldump of a database (reused by the export subsystem). */
    public function dumpDatabase(string $database): string
    {
        $c = $this->connection();
        $args = [
            $this->tool('mysqldump'),
            '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'],
            '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--default-character-set=utf8mb4',
            $database,
        ];

        $process = new Process($args, null, $this->processEnv($c), null, 900);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    private function import(string $database, string $sql): void
    {
        $c = $this->connection();
        $args = [
            $this->tool('mysql'),
            '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'],
            '--default-character-set=utf8mb4', $database,
        ];

        $process = new Process($args, null, $this->processEnv($c), $sql, 900);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql import failed: '.trim($process->getErrorOutput()));
        }
    }

    /** gunzip -t equivalent, in PHP. */
    private function verifyGzip(string $path): bool
    {
        if (! Storage::disk('local')->exists($path)) {
            return false;
        }
        $decoded = @gzdecode(Storage::disk('local')->get($path));

        return $decoded !== false && str_contains($decoded, 'CREATE TABLE');
    }

    /** Voucher/entry counts recorded at backup time, checked on restore. */
    private function counts(Tenant $tenant): array
    {
        return $tenant->run(fn () => [
            'vouchers' => (int) DB::table('vouchers')->count(),
            'voucher_entries' => (int) DB::table('voucher_entries')->count(),
            'ledgers' => (int) DB::table('ledgers')->count(),
        ]);
    }

    /**
     * Verify a freshly-imported database (by name): required tables present, voucher/entry
     * counts match the backup metadata, and double-entry balances to the paise
     * (the prove-balance equivalent). Uses schema-qualified raw queries, so it works on a
     * throwaway DB that is not a registered tenant.
     */
    private function verifyDatabase(string $db, TenantBackup $backup): void
    {
        $central = config('tenancy.database.central_connection');
        $conn = DB::connection($central);

        foreach (['vouchers', 'voucher_entries', 'ledgers', 'account_groups', 'companies'] as $table) {
            $exists = $conn->selectOne(
                'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                [$db, $table]
            );
            if (! $exists) {
                throw new RuntimeException("restored DB missing table [{$table}]");
            }
        }

        $expected = (array) $backup->meta;
        $vouchers = (int) $conn->selectOne("SELECT COUNT(*) AS c FROM `{$db}`.`vouchers`")->c;
        $entries = (int) $conn->selectOne("SELECT COUNT(*) AS c FROM `{$db}`.`voucher_entries`")->c;

        if (isset($expected['vouchers']) && $vouchers !== (int) $expected['vouchers']) {
            throw new RuntimeException("voucher count {$vouchers} != recorded {$expected['vouchers']}");
        }
        if (isset($expected['voucher_entries']) && $entries !== (int) $expected['voucher_entries']) {
            throw new RuntimeException("voucher_entry count {$entries} != recorded {$expected['voucher_entries']}");
        }

        $dr = (int) $conn->selectOne("SELECT COALESCE(SUM(amount),0) AS s FROM `{$db}`.`voucher_entries` WHERE dr_cr = 'Dr'")->s;
        $cr = (int) $conn->selectOne("SELECT COALESCE(SUM(amount),0) AS s FROM `{$db}`.`voucher_entries` WHERE dr_cr = 'Cr'")->s;
        if ($dr !== $cr) {
            throw new RuntimeException("restored data not balanced: Dr {$dr} != Cr {$cr}");
        }
    }

    private function restoredSlug(string $original): string
    {
        $base = substr(preg_replace('/[^a-z0-9]/', '', strtolower($original)), 0, 40);

        return $base.'rst'.now()->format('His');
    }

    private function retention(Tenant $tenant): array
    {
        $tier = (string) ($tenant->plan?->tier ?? '');
        $key = str_contains($tier, 'enterprise') ? 'enterprise' : 'default';

        return (array) config("zerobook.backup_retention.{$key}", config('zerobook.backup_retention.default'));
    }

    private function connection(): array
    {
        $default = config('database.default');

        return [
            'host' => config("database.connections.{$default}.host", '127.0.0.1'),
            'port' => (string) config("database.connections.{$default}.port", '3306'),
            'username' => config("database.connections.{$default}.username", 'root'),
            'password' => (string) config("database.connections.{$default}.password", ''),
        ];
    }

    /** Pass the password via MYSQL_PWD so it never appears in the process argument list. */
    private function processEnv(array $c): array
    {
        return $c['password'] !== '' ? ['MYSQL_PWD' => $c['password']] : [];
    }

    private function tool(string $name): string
    {
        // Prefer the configured mysql bin dir; fall back to PATH.
        $dir = rtrim((string) config('zerobook.mysql_bin_dir', env('ZEROBOOK_MYSQL_BIN', '')), '/\\');
        if ($dir !== '') {
            $exe = $dir.DIRECTORY_SEPARATOR.$name.(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '.exe' : '');
            if (is_file($exe)) {
                return $exe;
            }
        }

        return $name;
    }

    private function databaseExists(string $db): bool
    {
        $central = config('tenancy.database.central_connection');

        return DB::connection($central)->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]
        ) !== null;
    }

    private function alertBackupFailed(Tenant $tenant, string $reason): void
    {
        $admins = PlatformAdmin::all();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new BackupFailed($tenant->id, $reason));
        }
    }

    private function lifecycle(Tenant $tenant, string $event, ?string $notes = null): void
    {
        TenantLifecycleEvent::create([
            'tenant_id' => $tenant->id,
            'event' => $event,
            'notes' => $notes,
        ]);
    }
}
