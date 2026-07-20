<?php

namespace App\Livewire\Support;

use App\Support\TenantGate;
use Illuminate\Validation\ValidationException;
use Livewire\ComponentHook;

/**
 * Phase 14A — the global write-block for EVERY tenant Livewire action.
 *
 * Registered via Livewire::componentHook() in AppServiceProvider, this runs before any
 * Livewire component method is invoked over the update endpoint. If the method is a
 * WRITE action and TenantGate says the request may not write — the tenant is
 * 'suspended' / 'expired_trial', or it is a view-only platform-admin impersonation — the
 * action is refused before it runs, surfacing the same `tenant` validation error the
 * voucher chokepoint uses.
 *
 * Why a hook and not a per-method call: the write surface is ~40 actions across the
 * voucher screen, every master workspace, F11, currency, company/group management and the
 * forex revaluation. Enforcing at each site is easy to forget (the original 14A cut only
 * gated VoucherScreen::post/cancel, so quick-create ledgers/groups/stock-items and every
 * workspace save slipped through — a view-only impersonator could still write master
 * data). One denylist in one place closes the whole class and can't be re-opened by adding
 * a new screen. VoucherScreen::post/cancelVoucher keep their explicit assertWritable() as
 * defence-in-depth (they also run in-process from the prove harness, which has no hook).
 *
 * Reads are untouched: only the enumerated write actions are gated; report navigation
 * (period, drill, expand), company switching, and reference lookups all pass. On a central
 * request or with no active tenant, TenantGate::writable() is true, so nothing is blocked.
 */
class TenantWriteGuard extends ComponentHook
{
    /**
     * Every write action across the tenant Livewire surface. Keep in sync when adding a
     * new persisting action (grep: `public function (save|delete|saveQuick|add|make|post|
     * create|remove)` under app/Livewire).
     */
    private const WRITE_METHODS = [
        // Vouchers
        'post', 'cancelVoucher',
        // Every master workspace (Group/Ledger/CostCentre/Tds/Currency/Company + inventory)
        'saveSingle', 'saveMulti', 'saveAlter', 'deleteMaster',
        // Inline quick-create (Alt+C) — the gap the adversarial review confirmed
        'saveQuickLedger', 'saveQuickGroup', 'saveQuickStockItem', 'saveQuickStockGroup', 'saveQuickUnit',
        // Currency master
        'addCurrency', 'makeBase', 'addRate',
        // F11 features
        'save',
        // Company groups (Phase 12B)
        'createGroup', 'addMember', 'removeMember', 'deleteGroup',
        // Forex revaluation posting (Phase 11)
        'postRevaluation',
    ];

    public function call($method, $params, $returnEarly, $metadata = null, $componentContext = null)
    {
        if (in_array($method, self::WRITE_METHODS, true) && ! TenantGate::writable()) {
            throw ValidationException::withMessages(['tenant' => TenantGate::blockMessage()]);
        }
    }
}
