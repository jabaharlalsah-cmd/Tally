<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Retire / restore a master — the Active-Inactive half of the Dibi Tech
 * master-data rule, and TallyPrime's own behaviour.
 *
 * WHY RETIRE RATHER THAN DELETE
 * A master with history cannot be deleted: its vouchers would be orphaned, which
 * is why every workspace's deleteMaster() refuses when the record is referenced.
 * That left no way to get a dead ledger out of the pickers. Retiring solves it —
 * the record stays readable on the vouchers that already use it, and stops being
 * offered for new ones (see baseList in resources/js/masters/select.js).
 *
 * NAMING: the method is `saveActiveState`, deliberately inside the `save` family
 * already covered by TenantWriteGuard::WRITE_METHODS. It persists, so a suspended
 * tenant and a view-only impersonation session must both be blocked from calling
 * it — the very hole zerobook:prove-write-guard was written to catch.
 */
trait TogglesMasterActive
{
    /** The Eloquent class this workspace manages. */
    abstract protected function masterModelClass(): string;

    /**
     * A human reason this master may not be retired, or null when it may be.
     * Workspaces override to add their own rules; the reserved-record rule below
     * is shared because every master honours it.
     */
    protected function retireBlockedReason(Model $record): ?string
    {
        if (($record->is_reserved ?? false)) {
            return 'Reserved masters are part of the chart of accounts and stay active.';
        }
        if (($record->is_base ?? false)) {
            return 'The base currency stays active.';
        }

        return null;
    }

    /** @return array{ok: bool, message: string, is_active?: bool} */
    public function saveActiveState(int $id, bool $active): array
    {
        $class = $this->masterModelClass();

        return DB::transaction(function () use ($class, $id, $active) {
            /** @var Model|null $record */
            $record = $class::query()->lockForUpdate()->find($id);
            if (! $record) {
                return ['ok' => false, 'message' => 'Not found.'];
            }

            // Restoring is always allowed; only retiring needs a reason check.
            if (! $active && ($reason = $this->retireBlockedReason($record)) !== null) {
                return ['ok' => false, 'message' => $reason];
            }

            $record->forceFill(['is_active' => $active])->save();

            return [
                'ok' => true,
                'is_active' => $active,
                'message' => ($record->name ?? 'Master').($active ? ' restored.' : ' retired — hidden from dropdowns.'),
            ];
        });
    }
}
