{{--
    Phase 16A — Settings → API Keys.

    Built on components.layouts.plain, which ships NO Tabler, NO Bootstrap, NO Alpine and no
    Vite bundle — it inlines its own <style>. So the only classes that exist here are the ones
    that layout defines: .zb-card, .btn, .err, .flash, .pill, .muted, .grid, .zb-hint, and bare
    table/th/td. Tabler classes (card, btn-primary, badge, modal) would render as unstyled
    markup, and the codebase's zb-modal/Alpine pattern is equally unavailable. Modals here are
    therefore Livewire-state driven with local styles, and the clipboard button is a
    self-contained IIFE modelled on the layout's own password eye-toggle.
--}}
<div>
    <style>
        .zb-modal-backdrop { position: fixed; inset: 0; background: rgba(16,33,29,.55); display: flex;
            align-items: center; justify-content: center; padding: 1.2rem; z-index: 1200; }
        .zb-modal-box { background: #fff; border-radius: 12px; max-width: 620px; width: 100%;
            box-shadow: 0 12px 40px rgba(16,33,29,.28); padding: 1.6rem; max-height: 90vh; overflow-y: auto; }
        .zb-keybox { display: flex; gap: .5rem; align-items: stretch; margin: .8rem 0 .3rem; }
        .zb-keybox code { flex: 1; background: #f2f6f4; border: 1px solid var(--line); border-radius: 8px;
            padding: .7rem .8rem; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .86rem;
            word-break: break-all; color: var(--ink); }
        .zb-warn { background: #fff4e0; color: #97590a; border: 1px solid #f3d9a8; border-radius: 8px;
            padding: .7rem .8rem; font-size: .85rem; margin: .9rem 0; }
        .zb-check { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0 .2rem; font-size: .88rem; }
        .zb-check input { margin-top: .25rem; }
        .btn[disabled] { opacity: .45; cursor: not-allowed; }
        .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .btn-ghost:hover { background: #f2f6f4; }
        .btn-danger { background: #b23b32; }
        .btn-danger:hover { background: #8f2f27; }
        .pill-revoked { background: #fdeceb; color: #b23b32; }
        .zb-scopes { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .3rem .9rem; }
        .zb-scopes label, .zb-companies label { display: flex; gap: .45rem; align-items: baseline;
            font-size: .85rem; font-weight: 400; color: var(--ink); text-transform: none; letter-spacing: 0; margin: .25rem 0; }
        .zb-scopes code { font-size: .78rem; color: var(--zb-dark); }
        .zb-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        .zb-empty { text-align: center; padding: 2rem 1rem; color: var(--muted); }
    </style>

    <div class="zb-card">
        <div class="zb-head">
            <div>
                <h1 style="font-size:1.25rem">API Keys</h1>
                <p class="sub" style="margin-bottom:0">
                    Let your own website or software read and write this account's books over the ZeroBook API.
                </p>
            </div>
            @unless ($this->newKey)
                <button type="button" class="btn" wire:click="openCreate">New key</button>
            @endunless
        </div>

        @if (session('api_flash'))
            <div class="flash" style="margin-top:1rem">{{ session('api_flash') }}</div>
        @endif

        <div style="overflow-x:auto; margin-top:1.2rem">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Key</th>
                        <th>Permissions</th>
                        <th>Companies</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($keys as $key)
                        <tr>
                            <td>
                                <strong>{{ $key->name }}</strong>
                                @if ($key->isRevoked())
                                    <span class="pill pill-revoked" style="margin-left:.35rem">revoked</span>
                                @elseif ($key->isExpired())
                                    <span class="pill pill-revoked" style="margin-left:.35rem">expired</span>
                                @else
                                    <span class="pill pill-active" style="margin-left:.35rem">active</span>
                                @endif
                                @if ($key->expires_at && ! $key->isExpired())
                                    <div class="zb-hint">Expires {{ $key->expires_at->format('d-M-Y') }}</div>
                                @endif
                            </td>
                            <td><code class="muted" style="font-size:.8rem">{{ $key->maskedKey() }}</code></td>
                            <td>
                                @foreach ($key->scopes() as $scope)
                                    <div style="font-size:.78rem">{{ $scope }}</div>
                                @endforeach
                            </td>
                            <td class="muted" style="font-size:.8rem">
                                @if ($key->authorizesAllCompanies())
                                    All companies
                                @else
                                    {{ $companies->whereIn('id', $key->authorizedCompanyIds())->pluck('name')->implode(', ') ?: '—' }}
                                @endif
                            </td>
                            <td class="muted" style="font-size:.8rem">
                                {{ $key->created_at?->format('d-M-Y') }}
                                @if ($key->created_by_email)
                                    <div class="zb-hint">{{ $key->created_by_email }}</div>
                                @endif
                            </td>
                            <td class="muted" style="font-size:.8rem">
                                {{ $key->last_used_at ? $key->last_used_at->diffForHumans() : 'Never' }}
                            </td>
                            <td style="text-align:right; white-space:nowrap">
                                <a href="{{ route('account.api-keys.activity', $key) }}" wire:navigate
                                   style="font-size:.82rem; margin-right:.6rem">Activity</a>
                                @unless ($key->isRevoked())
                                    <button type="button" class="btn btn-danger" style="padding:.3rem .7rem; font-size:.8rem"
                                            wire:click="confirmRevoke({{ $key->id }})">Revoke</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="zb-empty">
                                No API keys yet.<br>
                                <span style="font-size:.85rem">Issue one to connect your website or software to this account.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── create form ─────────────────────────────────────────────────────── --}}
    @if ($showCreate)
        <div class="zb-card" style="margin-top:1.2rem">
            <h1 style="font-size:1.05rem">New API key</h1>
            <p class="sub">The key is shown once, when you create it. Store it somewhere safe.</p>

            <label for="k-name">Name</label>
            <input id="k-name" type="text" wire:model="name" placeholder="e.g. HMS Production" autocomplete="off">
            <div class="zb-hint">A label you will recognise later — where this key is used.</div>
            @error('name') <div class="err">{{ $message }}</div> @enderror

            <label style="margin-top:1.1rem">Permissions</label>
            <div class="zb-scopes">
                @foreach ($catalog as $scope => $description)
                    <label>
                        <input type="checkbox" value="{{ $scope }}" wire:model="permissions">
                        <span><code>{{ $scope }}</code><br><span class="muted" style="font-size:.76rem">{{ $description }}</span></span>
                    </label>
                @endforeach
            </div>
            <div class="zb-hint">Grant only what the integration needs. Scopes are enforced on every request.</div>
            @error('permissions') <div class="err">{{ $message }}</div> @enderror

            <label style="margin-top:1.1rem">Companies</label>
            <div class="zb-companies">
                <label><input type="radio" value="all" wire:model.live="companyMode"> <span>All companies in this account</span></label>
                <label><input type="radio" value="selected" wire:model.live="companyMode"> <span>Only the companies I choose</span></label>
            </div>
            @if ($companyMode === 'selected')
                <div class="zb-companies" style="margin-left:1.2rem; margin-top:.3rem">
                    @foreach ($companies as $company)
                        <label>
                            <input type="checkbox" value="{{ $company->id }}" wire:model="companyIds">
                            <span>{{ $company->name }} <span class="muted" style="font-size:.76rem">({{ $company->slug }})</span></span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('companyIds') <div class="err">{{ $message }}</div> @enderror

            <div class="grid" style="margin-top:1.1rem">
                <div>
                    <label for="k-exp">Expires on <span class="muted" style="font-weight:400">(optional)</span></label>
                    <input id="k-exp" type="date" wire:model="expiresAt">
                    <div class="zb-hint">Leave blank for a key that never expires.</div>
                    @error('expiresAt') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="k-rate">Requests per minute <span class="muted" style="font-weight:400">(optional)</span></label>
                    <input id="k-rate" type="number" wire:model="rateLimit" placeholder="{{ config('zerobook.api.rate_limit_per_min', 60) }}">
                    <div class="zb-hint">Blank uses the default of {{ config('zerobook.api.rate_limit_per_min', 60) }}/min.</div>
                    @error('rateLimit') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>

            <div style="margin-top:1.3rem; display:flex; gap:.6rem">
                <button type="button" class="btn" wire:click="create" wire:loading.attr="disabled">Create key</button>
                <button type="button" class="btn btn-ghost" wire:click="cancelCreate">Cancel</button>
            </div>
        </div>
    @endif

    {{-- ── show-once modal ─────────────────────────────────────────────────────
         Deliberately hard to dismiss by accident: no backdrop click-to-close, no Esc handler,
         and the only close button stays disabled until the user ticks the acknowledgement. This
         is the single moment the key exists in readable form — an accidental dismissal costs a
         revoke-and-reissue across every system that was about to hold it.
    --}}
    @if ($newKey)
        <div class="zb-modal-backdrop" wire:key="new-key-modal">
            <div class="zb-modal-box" role="dialog" aria-modal="true" aria-labelledby="nk-title">
                <h1 id="nk-title" style="font-size:1.15rem">Copy your API key now</h1>
                <p class="sub" style="margin-bottom:.4rem">
                    This is the only time <strong>“{{ $newKeyName }}”</strong> will ever be shown.
                </p>

                <div class="zb-keybox">
                    <code id="nk-value">{{ $newKey }}</code>
                    <button type="button" class="btn" id="nk-copy" style="white-space:nowrap">Copy</button>
                </div>

                <div class="zb-warn">
                    ZeroBook stores only a one-way hash of this key — we cannot show it to you again or
                    recover it. If you lose it, revoke this key and issue a new one.
                </div>

                <label class="zb-check" for="nk-ack" style="text-transform:none; letter-spacing:0; color:var(--ink); font-weight:400">
                    <input type="checkbox" id="nk-ack">
                    <span>I've saved this key somewhere safe — I understand it won't be shown again.</span>
                </label>

                <div style="margin-top:1.1rem; text-align:right">
                    <button type="button" class="btn" id="nk-done" disabled wire:click="dismissNewKey">Done</button>
                </div>
            </div>
        </div>

        {{-- Self-contained progressive enhancement: this layout ships no Alpine and no bundle.
             Modelled on plain.blade.php's password eye-toggle IIFE. --}}
        <script>
            (function () {
                var ack = document.getElementById('nk-ack');
                var done = document.getElementById('nk-done');
                var copy = document.getElementById('nk-copy');
                var value = document.getElementById('nk-value');
                if (!ack || !done || !copy || !value) return;

                ack.addEventListener('change', function () { done.disabled = !ack.checked; });

                copy.addEventListener('click', function () {
                    var text = value.textContent.trim();
                    var done_ = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy'; }, 1600); };
                    // navigator.clipboard needs a secure context; fall back to a selection copy
                    // so this still works over plain http on a local install.
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(done_, fallback);
                    } else {
                        fallback();
                    }
                    function fallback() {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); done_(); } catch (e) { copy.textContent = 'Press Ctrl+C'; }
                        document.body.removeChild(ta);
                    }
                });
            })();
        </script>
    @endif

    {{-- ── revoke confirmation ─────────────────────────────────────────────── --}}
    @if ($revoking)
        @php($revokingKey = $keys->firstWhere('id', $revoking))
        @if ($revokingKey)
            <div class="zb-modal-backdrop" wire:key="revoke-modal">
                <div class="zb-modal-box" style="max-width:480px" role="dialog" aria-modal="true">
                    <h1 style="font-size:1.05rem">Revoke “{{ $revokingKey->name }}”?</h1>
                    <p class="sub">
                        Any website or software using this key will start receiving errors immediately.
                        This cannot be undone — you would need to issue a new key.
                    </p>

                    <label for="rv-name">Type <strong>{{ $revokingKey->name }}</strong> to confirm</label>
                    <input id="rv-name" type="text" wire:model="revokeConfirmName" autocomplete="off">
                    @error('revokeConfirmName') <div class="err" style="margin-top:.5rem">{{ $message }}</div> @enderror

                    <div style="margin-top:1.2rem; display:flex; gap:.6rem; justify-content:flex-end">
                        <button type="button" class="btn btn-ghost" wire:click="cancelRevoke">Cancel</button>
                        <button type="button" class="btn btn-danger" wire:click="revoke">Revoke key</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
