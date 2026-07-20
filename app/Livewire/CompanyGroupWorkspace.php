<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Company;
use App\Models\CompanyGroup;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Phase 12B — Company Groups (Companies › Groups): create a group, add/remove
 * member companies, delete a group. A tenant-level admin screen (the CurrencyMaster
 * pattern — plain re-render Livewire), NOT company-scoped: groups span companies.
 *
 * Rules enforced here:
 *   • a company belongs to at most ONE group (the pivot's unique(company_id);
 *     the picker only offers ungrouped companies);
 *   • deleting a group cascades the membership rows and touches no company —
 *     existing ledger links become INERT (no group ⇒ no tagging), never invalid;
 *   • a single-member group is allowed as a stepping stone; tagging only ever
 *     fires between two companies of the same group.
 */
class CompanyGroupWorkspace extends Component
{
    use GuardsActiveCompany;

    public string $name = '';

    public ?string $notes = null;

    public ?int $memberGroupId = null;   // "add member" target group

    public ?int $memberCompanyId = null; // company to add

    public string $flash = '';

    public function createGroup(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('company_groups', 'name')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        CompanyGroup::create([
            'name' => trim($this->name),
            'slug' => CompanyGroup::uniqueSlugFor($this->name),
            'notes' => $this->notes ? trim($this->notes) : null,
        ]);

        $this->reset('name', 'notes');
        $this->flash = 'Group created — add its member companies below.';
    }

    public function addMember(): void
    {
        $this->validate([
            'memberGroupId' => ['required', 'integer', Rule::exists('company_groups', 'id')],
            'memberCompanyId' => ['required', 'integer', Rule::exists('companies', 'id')],
        ], [], ['memberGroupId' => 'group', 'memberCompanyId' => 'company']);

        if (CompanyGroup::forCompany($this->memberCompanyId)) {
            $this->addError('memberCompanyId', 'That company is already in a group — a company belongs to at most one group at a time.');

            return;
        }

        $group = CompanyGroup::find($this->memberGroupId);
        $group->companies()->attach($this->memberCompanyId);

        $company = Company::find($this->memberCompanyId);
        $this->reset('memberCompanyId');
        $this->flash = "“{$company->name}” added to “{$group->name}”.";
    }

    public function removeMember(int $groupId, int $companyId): void
    {
        CompanyGroup::find($groupId)?->companies()->detach($companyId);
        $this->flash = 'Company removed from the group. Its books are untouched; its inter-company links are now inert.';
    }

    public function deleteGroup(int $groupId): void
    {
        $group = CompanyGroup::find($groupId);
        if (! $group) {
            return;
        }

        $name = $group->name;
        $group->delete(); // membership cascades; companies untouched

        $this->flash = "Group “{$name}” deleted. Member companies and their books are untouched; tagging no longer applies between them.";
    }

    public function render()
    {
        return view('livewire.company-group-workspace', [
            'groups' => CompanyGroup::with('companies')->orderBy('name')->get(),
            'ungrouped' => Company::where('is_active', true)
                ->whereNotIn('id', function ($q) {
                    $q->select('company_id')->from('company_group_members');
                })
                ->orderBy('name')->get(),
        ]);
    }
}
