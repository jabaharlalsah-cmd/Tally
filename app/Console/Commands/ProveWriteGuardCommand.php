<?php

namespace App\Console\Commands;

use App\Livewire\Support\TenantWriteGuard;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves that every persisting Livewire action is covered by TenantWriteGuard.
 *
 * WHY THIS EXISTS
 * TenantWriteGuard is the security boundary that stops a SUSPENDED tenant, and a
 * VIEW-ONLY impersonation session, from writing to the books. It works off a
 * hand-maintained allowlist of method names. A new write action whose name is
 * not on that list is not blocked — it bypasses the guard completely, silently,
 * and no existing test notices, because the other proofs assert the same fixed
 * list rather than the real surface.
 *
 * The class docblock already says "keep in sync when adding a new persisting
 * action". A comment is not a guard. This command is.
 *
 * HOW IT DECIDES WHAT COUNTS AS A WRITE
 * It reflects over every App\Livewire component and flags public methods whose
 * name begins with a persisting verb (save/delete/create/add/make/post/remove/
 * update/store/toggle/activate/deactivate/promote/apply). Anything matching that
 * shape must either be on the allowlist or on the explicit exemption list below,
 * where the reason is written down.
 *
 * Deliberately conservative: it would rather flag a harmless read than miss a
 * write. Adding a genuinely non-persisting method to EXEMPT costs one line and a
 * justification.
 */
class ProveWriteGuardCommand extends Command
{
    protected $signature = 'zerobook:prove-write-guard';

    protected $description = 'Prove every persisting Livewire action is covered by TenantWriteGuard';

    /**
     * Write-shaped method names that do NOT persist, with the reason. Each entry
     * has been read and confirmed; do not add one without checking the body.
     */
    private const EXEMPT = [
        // Opens the delete confirmation UI; the actual delete is deleteMaster.
        'askDelete',
        // Pure client-state helpers on report/list screens.
        'toggleDetailed', 'toggleRow', 'toggleSection', 'toggleExpand',
        // Read-side: builds an export payload, writes nothing to the books.
        'makePayload',
        // BudgetEditor — these edit the in-memory $this->lines array only. The
        // persist is save(), which IS guarded. Verified by reading each body.
        'addGroup', 'addLedger', 'removeLine',
        // ScenarioPicker::toggle — session-scoped report selection, no book write.
        'toggle',
        // Livewire property hooks (updatedX). They clear flash state and
        // recompute a report; none persists. Verified by reading each body.
        'updatedPeriod', 'updatedFyStart', 'updatedCarryForward', 'updatedScenarioId',
        // Form dismissals — reset component state, touch no database row.
        // NOT to be confused with DayBook::cancel, which deletes a voucher and
        // IS guarded.
        'cancelCreate', 'cancelRevoke', 'cancelForm',
    ];

    /**
     * 'cancel' is in this list because VoucherScreen::cancelVoucher persists —
     * omitting the verb would have let the proof report that allowlist entry as
     * stale while never actually checking it.
     */
    private const VERBS = '/^(save|delete|create|add|make|post|remove|update|store|toggle|activate|deactivate|promote|apply|cancel)/i';

    private int $failures = 0;

    public function handle(): int
    {
        $this->info('Proving TenantWriteGuard covers every persisting Livewire action…');
        $this->newLine();

        $allow = $this->allowlist();
        $this->line('  Allowlist holds '.count($allow).' method names.');
        $this->newLine();

        $found = [];
        foreach ($this->livewireClasses() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Only methods DECLARED on the component itself — inherited
                // framework methods (mount, render, dehydrate…) are not ours.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $name = $method->getName();
                if (! preg_match(self::VERBS, $name)) {
                    continue;
                }
                if (in_array($name, self::EXEMPT, true)) {
                    continue;
                }
                $found[$name][] = class_basename($class);
            }
        }

        ksort($found);
        foreach ($found as $name => $classes) {
            $covered = in_array($name, $allow, true);
            $where = implode(', ', array_unique($classes));
            if ($covered) {
                $this->line("  <fg=green>[PASS]</> {$name} — guarded ({$where})");
            } else {
                $this->failures++;
                $this->line("  <fg=red>[FAIL]</> {$name} — NOT in TenantWriteGuard::WRITE_METHODS ({$where})");
            }
        }

        $this->newLine();
        $this->line('  Checked '.count($found).' write-shaped methods across app/Livewire.');

        // The reverse direction: an allowlist entry with no method behind it is
        // dead weight that makes the list harder to trust.
        $stale = array_diff($allow, array_keys($found), self::EXEMPT);
        foreach ($stale as $name) {
            $this->line("  <fg=yellow>[WARN]</> allowlist entry '{$name}' matches no Livewire method — stale?");
        }

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("SOME ASSERTIONS FAILED — {$this->failures} unguarded write action(s).");
            $this->line('  A suspended tenant or a view-only impersonation session could call these.');
            $this->line('  Add each to TenantWriteGuard::WRITE_METHODS, or to this command\'s EXEMPT list');
            $this->line('  with a reason if it genuinely does not persist.');

            return self::FAILURE;
        }

        $this->info('ALL WRITE-GUARD ASSERTIONS PASSED — every persisting action is covered.');

        return self::SUCCESS;
    }

    /** TenantWriteGuard's private allowlist, read by reflection. */
    private function allowlist(): array
    {
        return (new ReflectionClass(TenantWriteGuard::class))->getConstant('WRITE_METHODS');
    }

    /** Every App\Livewire component class on disk. */
    private function livewireClasses(): array
    {
        $base = app_path('Livewire');
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Livewire\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (class_exists($class)) {
                $out[] = $class;
            }
        }
        sort($out);

        return $out;
    }
}
