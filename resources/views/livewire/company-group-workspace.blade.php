<div class="zb-ws" style="max-width:860px;margin:0 auto">
    <div class="zb-panel">
        <div class="zb-panel-title">Company Groups</div>
        <p class="text-muted" style="font-size:.8rem;margin:-.2rem 0 .8rem">
            A group declares companies as RELATED (one owner's businesses). Transactions between grouped
            companies must carry the inter-company tag — the plumbing Phase 12C's consolidation eliminates.
            Ungrouped companies (a CA firm's clients) are completely unaffected.
        </p>

        @if ($flash)
            <div class="zb-ws-flash is-ok" style="position:static;margin-bottom:.8rem">{{ $flash }}</div>
        @endif

        {{-- create --}}
        <form wire:submit.prevent="createGroup" class="zb-form-row" style="gap:.5rem;align-items:flex-end">
            <div style="flex:1">
                <label for="cg-name">New group</label>
                <input id="cg-name" class="form-control zb-field" wire:model="name" autocomplete="off" placeholder="e.g. Global Holdings">
            </div>
            <div style="flex:1">
                <label for="cg-notes">Notes</label>
                <input id="cg-notes" class="form-control zb-field" wire:model="notes" autocomplete="off" placeholder="optional">
            </div>
            <button type="submit" class="btn zb-btn-primary btn-sm">Create Group</button>
        </form>
        @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror

        {{-- add member --}}
        <form wire:submit.prevent="addMember" class="zb-form-row" style="gap:.5rem;align-items:flex-end;margin-top:.9rem">
            <div style="flex:1">
                <label for="cg-member-group">Add company</label>
                <select id="cg-member-group" class="form-select zb-field" wire:model="memberGroupId">
                    <option value="">— pick a group —</option>
                    @foreach ($groups as $g)
                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1">
                <label for="cg-member-company">Company (ungrouped only)</label>
                <select id="cg-member-company" class="form-select zb-field" wire:model="memberCompanyId">
                    <option value="">— pick a company —</option>
                    @foreach ($ungrouped as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn zb-btn-primary btn-sm">Add to Group</button>
        </form>
        @error('memberGroupId') <div class="zb-field-error">{{ $message }}</div> @enderror
        @error('memberCompanyId') <div class="zb-field-error">{{ $message }}</div> @enderror

        {{-- groups + members --}}
        <table class="zb-rtable" style="margin-top:1.1rem">
            <thead><tr><th>Group</th><th>Members</th><th style="width:9rem"></th></tr></thead>
            <tbody>
                @forelse ($groups as $g)
                    <tr>
                        <td>
                            <strong>{{ $g->name }}</strong>
                            @if ($g->notes)<div class="text-muted" style="font-size:.74rem">{{ $g->notes }}</div>@endif
                            @if ($g->companies->count() < 2)
                                <div class="text-muted" style="font-size:.74rem">needs a second member before tagging applies</div>
                            @endif
                        </td>
                        <td>
                            @forelse ($g->companies as $c)
                                <span style="display:inline-flex;align-items:center;gap:.3rem;margin:0 .5rem .25rem 0">
                                    {{ $c->name }}
                                    <a href="#" wire:click.prevent="removeMember({{ $g->id }}, {{ $c->id }})" title="Remove from group" style="text-decoration:none">×</a>
                                </span>
                            @empty
                                <span class="text-muted">no members yet</span>
                            @endforelse
                        </td>
                        <td style="text-align:right">
                            <button type="button" class="btn btn-sm" wire:click="deleteGroup({{ $g->id }})"
                                    wire:confirm="Delete the group “{{ $g->name }}”? Member companies and their books are untouched; inter-company tagging stops applying between them.">
                                Delete group
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted">No groups yet — this tenant behaves exactly as before (no inter-company tagging anywhere).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
