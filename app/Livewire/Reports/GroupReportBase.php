<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\CompanyGroup;
use App\Services\GroupConsolidationService;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 12C-2 — the shared shell of the three group reports: group selection
 * (defaulting to the active company's group), the F2 period, and the
 * consolidation-adjustments audit panel. Read-only throughout — consolidation
 * never posts; the screens only render what GroupConsolidationService computes.
 */
abstract class GroupReportBase extends Component
{
    use GuardsActiveCompany;

    public ?int $groupId = null;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        // Default to the ACTIVE company's group ONLY — an ungrouped company (a CA
        // firm's unrelated client book) is NOT silently served the first group's
        // consolidated books. The picker still lists groups (groups are tenant-
        // visible per 12B — presentation, not an access boundary), so a user who
        // genuinely wants a group can select one.
        $this->groupId = CompanyGroup::forCompany(ActiveCompany::id())?->id;

        // Default period: the active company's fiscal year (12A's fyOpenFor).
        $today = Carbon::today();
        $this->from = \App\Models\Voucher::fyOpenFor(\App\Models\Voucher::fyStartFor($today))->toDateString();
        $this->to = $today->toDateString();
    }

    protected function group(): ?CompanyGroup
    {
        return $this->groupId ? CompanyGroup::with('companies')->find($this->groupId) : null;
    }

    protected function period(): array
    {
        $from = $this->from ? Carbon::parse($this->from) : Carbon::today()->startOfYear();
        $to = $this->to ? Carbon::parse($this->to) : Carbon::today();

        return [$from, $to];
    }

    /** Everything every group screen needs: group list, panel, period labels. */
    protected function shellData(GroupConsolidationService $svc): array
    {
        $group = $this->group();
        [$from, $to] = $this->period();

        return [
            'group' => $group,
            'groups' => CompanyGroup::orderBy('name')->get(['id', 'name']),
            'panel' => $group ? $svc->adjustmentsPanel($group, $from, $to) : null,
            'fromLabel' => $from->format('d-M-Y'),
            'toLabel' => $to->format('d-M-Y'),
            'memberNames' => $group ? $group->companies->pluck('name', 'id')->all() : [],
        ];
    }
}
