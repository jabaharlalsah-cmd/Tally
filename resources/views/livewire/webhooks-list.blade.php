{{--
    Phase 16C — Settings → Webhooks.

    Built on components.layouts.plain, which ships NO Tabler, NO Bootstrap, NO Alpine and no Vite
    bundle — only the classes that layout's inline <style> defines (.zb-card, .btn, .err, .flash,
    .pill, .muted, .grid, .zb-hint, bare table/th/td). Modals are Livewire-state driven with local
    styles; the copy button is a self-contained IIFE. Same shape as the 16A API Keys screen.
--}}
<div>
    <style>
        .zb-modal-backdrop { position: fixed; inset: 0; background: rgba(16,33,29,.55); display: flex;
            align-items: center; justify-content: center; padding: 1.2rem; z-index: 1200; }
        .zb-modal-box { background: #fff; border-radius: 12px; max-width: 640px; width: 100%;
            box-shadow: 0 12px 40px rgba(16,33,29,.28); padding: 1.6rem; max-height: 90vh; overflow-y: auto; }
        .zb-keybox { display: flex; gap: .5rem; align-items: stretch; margin: .8rem 0 .3rem; }
        .zb-keybox code { flex: 1; background: #f2f6f4; border: 1px solid var(--line); border-radius: 8px;
            padding: .7rem .8rem; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .82rem;
            word-break: break-all; color: var(--ink); }
        .zb-warn { background: #fff4e0; color: #97590a; border: 1px solid #f3d9a8; border-radius: 8px;
            padding: .7rem .85rem; font-size: .85rem; margin: .9rem 0; }
        .zb-check { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0 .2rem; font-size: .88rem; }
        .btn[disabled] { opacity: .45; cursor: not-allowed; }
        .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .btn-ghost:hover { background: #f2f6f4; }
        .btn-danger { background: #b23b32; } .btn-danger:hover { background: #8f2f27; }
        .pill-off { background: #eceff0; color: #5c6b63; }
        .pill-bad { background: #fdeceb; color: #b23b32; }
        .zb-events label, .zb-companies label { display: flex; gap: .45rem; align-items: baseline;
            font-size: .85rem; font-weight: 400; color: var(--ink); text-transform: none; letter-spacing: 0; margin: .25rem 0; }
        .zb-events code { font-size: .78rem; color: var(--zb-dark); }
        .zb-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        .zb-empty { text-align: center; padding: 2rem 1rem; color: var(--muted); }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .78rem; }
        .zb-sub { border: 1px solid var(--line); border-radius: 10px; padding: 1rem; margin-top: .8rem; background: #fff; }
        .zb-actions { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .7rem; }
        .zb-actions .btn { padding: .3rem .7rem; font-size: .78rem; }
    </style>

    <div class="zb-card">
        <div class="zb-head">
            <div>
                <h1 style="font-size:1.25rem">Webhooks</h1>
                <p class="sub" style="margin-bottom:0">
                    Have ZeroBook tell your website the moment something changes — instead of it asking, over and over.
                </p>
            </div>
            @unless ($this->newSecret)
                <button type="button" class="btn" wire:click="openCreate">New webhook</button>
            @endunless
        </div>

        @if (session('wh_flash'))
            <div class="flash" style="margin-top:1rem">{{ session('wh_flash') }}</div>
        @endif

        @forelse ($subscriptions as $sub)
            <div class="zb-sub">
                <div class="zb-head">
                    <div style="min-width:0">
                        <strong class="mono" style="font-size:.85rem">{{ $sub->url }}</strong>
                        @if ($sub->isDisabled())
                            <span class="pill pill-bad" style="margin-left:.35rem">auto-disabled</span>
                        @elseif (! $sub->is_active)
                            <span class="pill pill-off" style="margin-left:.35rem">paused</span>
                        @else
                            <span class="pill pill-active" style="margin-left:.35rem">active</span>
                        @endif
                        @if ($sub->description)
                            <div class="zb-hint">{{ $sub->description }}</div>
                        @endif
                        <div class="zb-hint">
                            {{ implode(', ', $sub->eventTypes()) }}
                            ·
                            @if ($sub->authorizesAllCompanies())
                                all companies
                            @else
                                {{ $companies->whereIn('id', $sub->authorizedCompanyIds())->pluck('name')->implode(', ') ?: '—' }}
                            @endif
                        </div>
                    </div>
                    <div class="muted" style="font-size:.78rem; text-align:right; white-space:nowrap">
                        {{ $sub->last_delivery_at ? 'last '.$sub->last_delivery_at->diffForHumans() : 'never delivered' }}
                        @if ($sub->consecutive_failures > 0)
                            <div style="color:#b23b32">{{ $sub->consecutive_failures }} failing</div>
                        @endif
                    </div>
                </div>

                @if ($sub->isDisabled())
                    <div class="zb-warn" style="margin:.7rem 0 0">
                        {{ $sub->disabled_reason }} Fix your endpoint, then re-enable to resume.
                    </div>
                @endif

                <div class="zb-actions">
                    <button type="button" class="btn btn-ghost" wire:click="sendTest({{ $sub->id }})">Send test</button>
                    <button type="button" class="btn btn-ghost" wire:click="viewDeliveries({{ $sub->id }})">
                        {{ $viewingDeliveries === $sub->id ? 'Hide' : 'Deliveries' }}
                    </button>
                    <button type="button" class="btn btn-ghost" wire:click="openEdit({{ $sub->id }})">Edit</button>
                    <button type="button" class="btn btn-ghost" wire:click="toggleActive({{ $sub->id }})">
                        {{ $sub->is_active ? 'Pause' : 'Enable' }}
                    </button>
                    <button type="button" class="btn btn-ghost" wire:click="confirmRotate({{ $sub->id }})">Rotate secret</button>
                    <button type="button" class="btn btn-danger" wire:click="confirmDelete({{ $sub->id }})">Delete</button>
                </div>

                @if ($viewingDeliveries === $sub->id)
                    <div style="overflow-x:auto; margin-top:1rem">
                        <table>
                            <thead><tr><th>When</th><th>Event</th><th>Status</th><th>Try</th><th>Code</th><th></th></tr></thead>
                            <tbody>
                                @forelse ($deliveries as $d)
                                    <tr>
                                        <td class="muted" style="font-size:.78rem; white-space:nowrap">{{ $d->created_at?->format('d-M H:i:s') }}</td>
                                        <td class="mono">{{ $d->event_type }}</td>
                                        <td>
                                            <span class="pill {{ $d->status === 'succeeded' ? 'pill-active' : ($d->status === 'exhausted' ? 'pill-bad' : 'pill-off') }}">{{ $d->status }}</span>
                                        </td>
                                        <td class="muted" style="font-size:.78rem">{{ $d->attempt_count }}</td>
                                        <td class="muted mono">{{ $d->last_response_status ?? '—' }}</td>
                                        <td style="text-align:right">
                                            <button type="button" class="btn btn-ghost" style="padding:.2rem .5rem; font-size:.74rem"
                                                    wire:click="redeliver({{ $d->id }})">Redeliver</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="zb-empty">No deliveries yet. Send a test event to check your endpoint.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <div class="zb-empty">
                No webhooks yet.<br>
                <span style="font-size:.85rem">Add one to have ZeroBook push events to your site as they happen.</span>
            </div>
        @endforelse
    </div>

    {{-- ── create / edit form ───────────────────────────────────────────────── --}}
    @if ($showForm)
        <div class="zb-card" style="margin-top:1.2rem">
            <h1 style="font-size:1.05rem">{{ $editingId ? 'Edit webhook' : 'New webhook' }}</h1>
            <p class="sub">We POST a signed JSON event to this URL whenever a chosen event happens.</p>

            <label for="wh-url">Endpoint URL</label>
            <input id="wh-url" type="text" wire:model="url" placeholder="https://your-site.example/zerobook/hook" autocomplete="off">
            @error('url') <div class="err">{{ $message }}</div> @enderror

            <label for="wh-desc" style="margin-top:1rem">Description <span class="muted" style="font-weight:400">(optional)</span></label>
            <input id="wh-desc" type="text" wire:model="description" placeholder="e.g. HMS billing sync" autocomplete="off">

            <label style="margin-top:1.1rem">Events</label>
            <div class="zb-events">
                <label>
                    <input type="checkbox" value="*" wire:model="events">
                    <span><code>*</code> <span class="muted" style="font-size:.76rem">— everything, including events added later</span></span>
                </label>
                @foreach ($catalog as $event => $desc)
                    <label>
                        <input type="checkbox" value="{{ $event }}" wire:model="events">
                        <span><code>{{ $event }}</code> <span class="muted" style="font-size:.76rem">— {{ $desc }}</span></span>
                    </label>
                @endforeach
            </div>
            @error('events') <div class="err">{{ $message }}</div> @enderror

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

            <div style="margin-top:1.3rem; display:flex; gap:.6rem">
                <button type="button" class="btn" wire:click="save" wire:loading.attr="disabled">{{ $editingId ? 'Save changes' : 'Create webhook' }}</button>
                <button type="button" class="btn btn-ghost" wire:click="cancelForm">Cancel</button>
            </div>
        </div>
    @endif

    {{-- ── show-once secret modal ───────────────────────────────────────────────
         Same discipline as the API-key modal: no backdrop close, no Esc, and the Done button stays
         disabled until acknowledged. This is the only moment the signing secret is readable.
    --}}
    @if ($newSecret)
        <div class="zb-modal-backdrop" wire:key="new-secret-modal">
            <div class="zb-modal-box" role="dialog" aria-modal="true" aria-labelledby="ns-title">
                <h1 id="ns-title" style="font-size:1.15rem">Copy your signing secret now</h1>
                <p class="sub" style="margin-bottom:.4rem">
                    This is the only time the secret for <strong class="mono">{{ $newSecretUrl }}</strong> will be shown.
                </p>

                <div class="zb-keybox">
                    <code id="ns-value">{{ $newSecret }}</code>
                    <button type="button" class="btn" id="ns-copy" style="white-space:nowrap">Copy</button>
                </div>

                <div class="zb-warn">
                    Use this to verify the <code>X-ZeroBook-Signature</code> header on every event we send you —
                    it is how you know a request really came from ZeroBook. We cannot show it again;
                    if you lose it, rotate the secret and update your endpoint.
                </div>

                <label class="zb-check" for="ns-ack" style="text-transform:none; letter-spacing:0; color:var(--ink); font-weight:400">
                    <input type="checkbox" id="ns-ack">
                    <span>I've saved this secret somewhere safe — I understand it won't be shown again.</span>
                </label>

                <div style="margin-top:1.1rem; text-align:right">
                    <button type="button" class="btn" id="ns-done" disabled wire:click="dismissSecret">Done</button>
                </div>
            </div>
        </div>

        {{-- Self-contained: this layout ships no Alpine and no bundle. --}}
        <script>
            (function () {
                var ack = document.getElementById('ns-ack'), done = document.getElementById('ns-done');
                var copy = document.getElementById('ns-copy'), value = document.getElementById('ns-value');
                if (!ack || !done || !copy || !value) return;
                ack.addEventListener('change', function () { done.disabled = !ack.checked; });
                copy.addEventListener('click', function () {
                    var text = value.textContent.trim();
                    var ok = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy'; }, 1600); };
                    function fallback() {
                        var ta = document.createElement('textarea');
                        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy'); ok(); } catch (e) { copy.textContent = 'Press Ctrl+C'; }
                        document.body.removeChild(ta);
                    }
                    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(ok, fallback); }
                    else { fallback(); }
                });
            })();
        </script>
    @endif

    {{-- ── delete confirmation ──────────────────────────────────────────────── --}}
    @if ($deleting)
        <div class="zb-modal-backdrop" wire:key="delete-modal">
            <div class="zb-modal-box" style="max-width:460px" role="dialog" aria-modal="true">
                <h1 style="font-size:1.05rem">Delete this webhook?</h1>
                <p class="sub">Its delivery history goes too, and no further events will be sent. Your books are unaffected.</p>
                <div style="margin-top:1.2rem; display:flex; gap:.6rem; justify-content:flex-end">
                    <button type="button" class="btn btn-ghost" wire:click="$set('deleting', null)">Cancel</button>
                    <button type="button" class="btn btn-danger" wire:click="deleteWebhook">Delete</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── rotate confirmation ──────────────────────────────────────────────── --}}
    @if ($rotating)
        <div class="zb-modal-backdrop" wire:key="rotate-modal">
            <div class="zb-modal-box" style="max-width:460px" role="dialog" aria-modal="true">
                <h1 style="font-size:1.05rem">Rotate the signing secret?</h1>
                <p class="sub">
                    The current secret stops working immediately — your endpoint will reject events until you
                    update it with the new one. You'll see the new secret once, next.
                </p>
                <div style="margin-top:1.2rem; display:flex; gap:.6rem; justify-content:flex-end">
                    <button type="button" class="btn btn-ghost" wire:click="$set('rotating', null)">Cancel</button>
                    <button type="button" class="btn" wire:click="rotateSecret">Rotate</button>
                </div>
            </div>
        </div>
    @endif
</div>
