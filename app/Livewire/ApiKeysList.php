<?php

namespace App\Livewire;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Api\ApiKeyService;
use App\Support\ApiScopes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Phase 16A — Settings → API Keys. List, issue and revoke a tenant's integration keys.
 *
 * ADMIN GATE — this screen is the tenant app's FIRST role-gated surface.
 * There is no Gate, no Policy, no role middleware and no admin-gated screen anywhere in 7B to
 * copy: /subscription, /companies, /features and /account/data are all open to any
 * authenticated user. There is also no 'admin' role — tenant_users.role is a plain string of
 * owner|accountant|member (default 'member'), so gating on 'admin' would lock out every user
 * alive. 'owner' is the privileged value (the platform console defaults new tenant admins to it,
 * and the proofs look admins up with where('role','owner')).
 *
 * The check is POSITIVE — role === 'owner' — never `role !== 'member'`, which would quietly
 * admit 'accountant'. It is enforced in mount() AND re-checked in every write action, because
 * mount() runs once while actions arrive on later requests against the same component.
 *
 * The raw key exists in $newKey for exactly one modal. It is cleared the moment the modal is
 * dismissed so it stops riding in the Livewire snapshot — the user has already seen it, and
 * nothing can recover it afterwards.
 */
#[Layout('components.layouts.plain')]
#[Title('API Keys — ZeroBook')]
class ApiKeysList extends Component
{
    /** The privileged tenant role. See the class docblock — this is 'owner', not 'admin'. */
    public const ADMIN_ROLE = 'owner';

    // ── create form ──────────────────────────────────────────────────────────
    public string $name = '';

    public array $permissions = [];

    public string $companyMode = 'all';   // 'all' | 'selected'

    public array $companyIds = [];

    public string $expiresAt = '';

    public string $rateLimit = '';

    public bool $showCreate = false;

    // ── show-once modal ──────────────────────────────────────────────────────
    public ?string $newKey = null;

    public ?string $newKeyName = null;

    // ── revoke confirmation ──────────────────────────────────────────────────
    public ?int $revoking = null;

    public string $revokeConfirmName = '';

    public function mount(): void
    {
        $this->assertAdmin();
    }

    /**
     * 403 unless the signed-in tenant user is an owner.
     *
     * Called from mount() and from every write action. A Livewire action is a fresh HTTP
     * request that re-hydrates the component without re-running mount(), so a mount-only gate
     * would leave create/revoke reachable by anyone who could forge an update call.
     */
    private function assertAdmin(): void
    {
        $user = Auth::guard('tenant')->user();

        if (! $user || $user->role !== self::ADMIN_ROLE) {
            abort(403, 'Only an account owner can manage API keys.');
        }
    }

    private function currentUser(): ?TenantUser
    {
        return Auth::guard('tenant')->user();
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->assertAdmin();

        $this->reset(['name', 'permissions', 'companyMode', 'companyIds', 'expiresAt', 'rateLimit']);
        $this->resetValidation();
        $this->companyMode = 'all';
        $this->showCreate = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreate = false;
        $this->resetValidation();
    }

    public function create(ApiKeyService $keys): void
    {
        $this->assertAdmin();

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:'.implode(',', ApiScopes::all())],
            'companyMode' => ['required', 'in:all,selected'],
            'companyIds' => ['array'],
            'companyIds.*' => ['integer'],
            'expiresAt' => ['nullable', 'date', 'after:today'],
            'rateLimit' => ['nullable', 'integer', 'min:1', 'max:6000'],
        ], [
            'permissions.required' => 'Choose at least one permission.',
            'expiresAt.after' => 'The expiry date must be in the future.',
        ]);

        // 'selected' with nothing selected would silently mean "all companies" (an empty list is
        // the wildcard), i.e. the opposite of what was asked for. Refuse instead.
        if ($validated['companyMode'] === 'selected' && $this->companyIds === []) {
            $this->addError('companyIds', 'Select at least one company, or choose "All companies".');

            return;
        }

        $companyIds = $validated['companyMode'] === 'all'
            ? []
            : array_map('intval', $this->companyIds);

        // Only ever authorize companies that exist and are active in THIS tenant — never trust
        // the ids the browser posted back.
        if ($companyIds !== []) {
            $valid = Company::whereIn('id', $companyIds)->where('is_active', true)->pluck('id')->all();

            if (count($valid) !== count($companyIds)) {
                $this->addError('companyIds', 'One of the selected companies is no longer available.');

                return;
            }
        }

        $result = $keys->generate(
            tenant: Tenant::find(tenant('id')),
            user: $this->currentUser(),
            name: $validated['name'],
            permissions: $this->permissions,
            companyIds: $companyIds,
            expiresAt: $validated['expiresAt'] ? Carbon::parse($validated['expiresAt'])->endOfDay() : null,
            rateLimitPerMin: $validated['rateLimit'] !== null && $validated['rateLimit'] !== ''
                ? (int) $validated['rateLimit']
                : null,
        );

        // The one and only time this value is ever displayed.
        $this->newKey = $result['key'];
        $this->newKeyName = $result['key_row']->name;

        $this->showCreate = false;
        $this->reset(['name', 'permissions', 'companyMode', 'companyIds', 'expiresAt', 'rateLimit']);
    }

    /** Dismiss the show-once modal and drop the raw key from component state for good. */
    public function dismissNewKey(): void
    {
        $this->newKey = null;
        $this->newKeyName = null;
    }

    // ── revoke ───────────────────────────────────────────────────────────────

    public function confirmRevoke(int $id): void
    {
        $this->assertAdmin();

        $this->revoking = $id;
        $this->revokeConfirmName = '';
        $this->resetValidation();
    }

    public function cancelRevoke(): void
    {
        $this->revoking = null;
        $this->revokeConfirmName = '';
        $this->resetValidation();
    }

    public function revoke(ApiKeyService $keys): void
    {
        $this->assertAdmin();

        $key = ApiKey::find($this->revoking);

        if (! $key) {
            $this->cancelRevoke();

            return;
        }

        // Typed-name confirmation, matching the house pattern for destructive actions.
        if (trim($this->revokeConfirmName) !== $key->name) {
            $this->addError('revokeConfirmName', 'Type the key name exactly to confirm.');

            return;
        }

        $keys->revoke($key, $this->currentUser());

        $this->cancelRevoke();
        session()->flash('api_flash', "Key “{$key->name}” has been revoked. Any integration using it will now be refused.");
    }

    public function render()
    {
        return view('livewire.api-keys-list', [
            'keys' => ApiKey::orderByRaw('revoked_at IS NOT NULL')->orderByDesc('id')->get(),
            'companies' => Company::where('is_active', true)->orderBy('id')->get(),
            'catalog' => ApiScopes::CATALOG,
        ]);
    }
}
