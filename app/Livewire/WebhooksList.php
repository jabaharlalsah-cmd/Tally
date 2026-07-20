<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\TenantUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Api\Webhooks\WebhookDispatcher;
use App\Services\Api\Webhooks\WebhookEvents;
use App\Services\Api\Webhooks\WebhookSigner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Phase 16C — Settings → Webhooks. Register endpoints, watch their health, replay a delivery.
 *
 * Mirrors ApiKeysList (16A) deliberately: the same owner-only gate, the same show-once secret
 * modal, the same plain-layout constraints. A customer who has issued an API key should find this
 * screen already familiar.
 *
 * OWNER-ONLY, and re-checked in every action — a Livewire action is a fresh request that
 * re-hydrates the component without re-running mount(), so a mount-only gate would leave the
 * writes reachable.
 */
#[Layout('components.layouts.plain')]
#[Title('Webhooks — ZeroBook')]
class WebhooksList extends Component
{
    /** The privileged tenant role. There is no 'admin' role — see ApiKeysList. */
    public const ADMIN_ROLE = 'owner';

    // ── create/edit form ─────────────────────────────────────────────────────
    public ?int $editingId = null;

    public string $url = '';

    public string $description = '';

    public array $events = [];

    public string $companyMode = 'all';   // 'all' | 'selected'

    public array $companyIds = [];

    public bool $showForm = false;

    // ── show-once secret modal ───────────────────────────────────────────────
    public ?string $newSecret = null;

    public ?string $newSecretUrl = null;

    // ── confirmations ────────────────────────────────────────────────────────
    public ?int $deleting = null;

    public ?int $rotating = null;

    /** The delivery log drawer. */
    public ?int $viewingDeliveries = null;

    public function mount(): void
    {
        $this->assertAdmin();
    }

    private function assertAdmin(): void
    {
        $user = Auth::guard('tenant')->user();

        if (! $user || $user->role !== self::ADMIN_ROLE) {
            abort(403, 'Only an account owner can manage webhooks.');
        }
    }

    private function currentUser(): ?TenantUser
    {
        return Auth::guard('tenant')->user();
    }

    // ── create / edit ────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->assertAdmin();
        $this->reset(['url', 'description', 'events', 'companyMode', 'companyIds', 'editingId']);
        $this->resetValidation();
        $this->companyMode = 'all';
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->assertAdmin();
        $sub = WebhookSubscription::findOrFail($id);

        $this->editingId = $sub->id;
        $this->url = $sub->url;
        $this->description = (string) $sub->description;
        $this->events = $sub->eventTypes();
        $this->companyIds = $sub->authorizedCompanyIds();
        $this->companyMode = $this->companyIds === [] ? 'all' : 'selected';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->assertAdmin();

        $rules = [
            'url' => ['required', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:191'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', array_merge(WebhookEvents::all(), [WebhookEvents::WILDCARD]))],
            'companyMode' => ['required', 'in:all,selected'],
        ];
        if (config('webhooks.require_https')) {
            $rules['url'][] = 'starts_with:https://';
        }

        $this->validate($rules, [
            'events.required' => 'Choose at least one event.',
            'url.starts_with' => 'The endpoint must use https.',
        ]);

        if ($this->companyMode === 'selected' && $this->companyIds === []) {
            $this->addError('companyIds', 'Select at least one company, or choose "All companies".');

            return;
        }

        $companyIds = $this->companyMode === 'all' ? [] : array_map('intval', $this->companyIds);

        if ($companyIds !== []) {
            $valid = Company::whereIn('id', $companyIds)->where('is_active', true)->pluck('id')->all();
            if (count($valid) !== count($companyIds)) {
                $this->addError('companyIds', 'One of the selected companies is no longer available.');

                return;
            }
        }

        $attrs = [
            'url' => trim($this->url),
            'description' => $this->description !== '' ? $this->description : null,
            'event_types_json' => WebhookEvents::sanitize($this->events),
            'authorized_company_ids_json' => $companyIds,
            'is_active' => true,
        ];

        if ($this->editingId) {
            $sub = WebhookSubscription::findOrFail($this->editingId);
            // Editing an auto-disabled webhook is the owner asserting it is healthy again.
            if ($sub->isDisabled()) {
                $attrs += ['disabled_at' => null, 'disabled_reason' => null, 'consecutive_failures' => 0];
            }
            $sub->update($attrs);
            session()->flash('wh_flash', 'Webhook updated.');
        } else {
            $secret = WebhookSigner::newSecret();
            $sub = WebhookSubscription::create($attrs + [
                'secret' => $secret,
                'created_by_user_id' => $this->currentUser()?->id,
                'created_by_email' => $this->currentUser()?->email,
            ]);
            // The one and only time this value is displayed.
            $this->newSecret = $secret;
            $this->newSecretUrl = $sub->url;
        }

        $this->showForm = false;
        $this->reset(['url', 'description', 'events', 'companyMode', 'companyIds', 'editingId']);
    }

    /** Dismiss the show-once modal and drop the secret from component state for good. */
    public function dismissSecret(): void
    {
        $this->newSecret = null;
        $this->newSecretUrl = null;
    }

    // ── toggle / delete / rotate / test / redeliver ──────────────────────────

    public function toggleActive(int $id): void
    {
        $this->assertAdmin();
        $sub = WebhookSubscription::findOrFail($id);

        $sub->update($sub->is_active
            ? ['is_active' => false]
            // Re-enabling by hand clears an auto-disable and grants a fresh failure budget.
            : ['is_active' => true, 'disabled_at' => null, 'disabled_reason' => null, 'consecutive_failures' => 0]);
    }

    public function confirmDelete(int $id): void
    {
        $this->assertAdmin();
        $this->deleting = $id;
    }

    public function deleteWebhook(): void
    {
        $this->assertAdmin();

        // DELETE THROUGH THE MODEL, not the query builder. A query-builder delete fires no model
        // events, so WebhookEmitter's live-subscription cache would keep serving this now-deleted
        // row for the rest of the process — and every delivery it queued would fail its foreign
        // key. Verified: with a builder delete, deleting ONE webhook silently stopped every OTHER
        // webhook in the tenant from receiving events.
        WebhookSubscription::whereKey($this->deleting)->first()?->delete();   // deliveries cascade

        $this->deleting = null;
        session()->flash('wh_flash', 'Webhook deleted. No further events will be sent to it.');
    }

    public function confirmRotate(int $id): void
    {
        $this->assertAdmin();
        $this->rotating = $id;
    }

    public function rotateSecret(): void
    {
        $this->assertAdmin();
        $sub = WebhookSubscription::findOrFail($this->rotating);

        $secret = WebhookSigner::newSecret();
        $sub->update(['secret' => $secret]);

        $this->rotating = null;
        $this->newSecret = $secret;
        $this->newSecretUrl = $sub->url;
    }

    public function sendTest(int $id, WebhookDispatcher $dispatcher): void
    {
        $this->assertAdmin();
        $sub = WebhookSubscription::findOrFail($id);

        if (! $sub->isLive()) {
            session()->flash('wh_flash', 'Enable the webhook before sending a test.');

            return;
        }

        $dispatcher->sendTest($sub);
        session()->flash('wh_flash', 'A signed ping was queued — it will be delivered within a minute.');
    }

    public function redeliver(int $deliveryId, WebhookDispatcher $dispatcher): void
    {
        $this->assertAdmin();
        $row = WebhookDelivery::findOrFail($deliveryId);

        $new = $dispatcher->redeliver($row);

        // redeliver() hands back the SAME row when it had not finished yet — there was nothing to
        // re-send. Say which happened rather than claiming a re-queue that did not occur.
        session()->flash('wh_flash', $new->is($row)
            ? 'That event has not been delivered yet — it is still queued and will be attempted automatically. Nothing further was queued.'
            : 'Event re-queued. It carries the ORIGINAL payload and event id, so your endpoint can still dedupe it.');
    }

    public function viewDeliveries(int $id): void
    {
        $this->assertAdmin();
        $this->viewingDeliveries = $this->viewingDeliveries === $id ? null : $id;
    }

    public function render()
    {
        return view('livewire.webhooks-list', [
            'subscriptions' => WebhookSubscription::orderByDesc('id')->get(),
            'companies' => Company::where('is_active', true)->orderBy('id')->get(),
            'catalog' => WebhookEvents::CATALOG,
            'deliveries' => $this->viewingDeliveries
                ? WebhookDelivery::where('webhook_subscription_id', $this->viewingDeliveries)->orderByDesc('id')->limit(20)->get()
                : collect(),
        ]);
    }
}
