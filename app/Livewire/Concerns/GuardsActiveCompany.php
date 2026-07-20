<?php

namespace App\Livewire\Concerns;

use App\Support\ActiveCompany;

/**
 * Phase 12A — the stale-tab guard.
 *
 * Every Livewire component's state (bootData caches, masters seeds, edit
 * snapshots) is a page-load snapshot of ONE company. If the user switches the
 * active company in another tab, this tab's session now points at the NEW
 * company while its snapshot still shows the OLD one — any commit from it would
 * write old-company-shaped data into the new company's books.
 *
 * The trait stamps the active company id into the component at mount (Livewire
 * signs it into the snapshot checksum, so it cannot be tampered with) and, on
 * every subsequent request, aborts with 409 when the session's active company no
 * longer matches. The user reloads and lands on the new company's screen —
 * exactly the "switching discards open screens" rule.
 */
trait GuardsActiveCompany
{
    public ?int $guardCompanyId = null;

    public function mountGuardsActiveCompany(): void
    {
        $this->guardCompanyId = ActiveCompany::id();
    }

    public function hydrateGuardsActiveCompany(): void
    {
        if ($this->guardCompanyId !== null && $this->guardCompanyId !== ActiveCompany::id()) {
            abort(409, 'The active company changed in another tab. Reload this screen to continue in the current company.');
        }
    }
}
