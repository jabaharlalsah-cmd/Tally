<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformAdminAction;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Voucher;
use App\Services\Platform\ImpersonationService;
use App\Services\Platform\PlatformActions;
use App\Services\Tenancy\InfraProvisioner;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 14A — the platform-admin console (served on admin.<central-domain>).
 *
 * See-all-tenants, manage lifecycle (suspend / reactivate / extend trial / change plan),
 * and impersonate for support. Deliberately READ-MOSTLY about the tenants' books: the
 * detail view shows activity COUNTS only — never a voucher amount or a ledger name — so
 * an operator can gauge usage without seeing a customer's private accounting data.
 */
class PlatformController extends Controller
{
    /** GET /admin — every tenant, filterable by status. */
    public function dashboard(Request $request)
    {
        $status = (string) $request->query('status', '');

        $query = Tenant::with('plan')->orderByDesc('created_at');
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        $tenants = $query->get();

        // Owner email + in-tenant company count for the list. The company count needs a
        // per-tenant DB switch (pilot scale — fine); a not-yet-provisioned or broken
        // tenant simply shows a dash.
        $ownerEmails = TenantUser::whereIn('tenant_id', $tenants->pluck('id'))
            ->where('role', 'owner')->orderBy('id')->get()->keyBy('tenant_id');

        $rows = $tenants->map(function (Tenant $t) use ($ownerEmails) {
            return [
                'tenant' => $t,
                'owner_email' => $ownerEmails[$t->id]->email ?? '—',
                'companies' => $this->safeCompanyCount($t),
            ];
        });

        return view('central.admin.tenants', [
            'rows' => $rows,
            'statusFilter' => $status,
            'statuses' => $this->knownStatuses(),
            'plans' => Plan::orderBy('price_inr')->get(),
            'countries' => config('zerobook.countries'),
        ]);
    }

    /** GET /admin/tenants/{tenant} — read-mostly detail + activity summary + audit log. */
    public function show(Tenant $tenant)
    {
        $tenant->load('plan');

        $owner = TenantUser::where('tenant_id', $tenant->id)->orderByRaw("role = 'owner' desc")->orderBy('id')->first();
        $users = TenantUser::where('tenant_id', $tenant->id)->get();

        $activity = $this->activitySummary($tenant);

        $log = PlatformAdminAction::with('admin')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')->limit(50)->get();

        // Phase 14B — subscription payment history.
        $payments = \App\Models\Payment::with(['plan', 'recordedByAdmin'])
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')->get();

        // Phase 14C — backups, exports, lifecycle events.
        $backups = \App\Models\TenantBackup::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(30)->get();
        $exports = \App\Models\TenantExport::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(15)->get();
        $lifecycle = \App\Models\TenantLifecycleEvent::with('admin')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(30)->get();

        return view('central.admin.tenant-detail', [
            'tenant' => $tenant,
            'owner' => $owner,
            'users' => $users,
            'activity' => $activity,
            'log' => $log,
            'plans' => Plan::orderBy('price_inr')->get(),
            'payments' => $payments,
            'backups' => $backups,
            'exports' => $exports,
            'lifecycle' => $lifecycle,
        ]);
    }

    /** GET /admin/lifecycle — every non-active tenant, sorted by next transition date. */
    public function lifecycle()
    {
        $statuses = ['suspended', 'expired_trial', 'expired_subscription', 'archived', 'purge_scheduled', 'restored', 'purged'];

        $tenants = Tenant::with('plan')
            ->whereIn('status', $statuses)
            ->orderByRaw('COALESCE(archive_scheduled_for, purge_scheduled_for, plan_ends_at, trial_ends_at) is null, COALESCE(archive_scheduled_for, purge_scheduled_for, plan_ends_at, trial_ends_at) asc')
            ->get();

        return view('central.admin.lifecycle', ['tenants' => $tenants]);
    }

    /** POST /admin/tenants — manually provision a tenant (ops onboarding). */
    public function provision(Request $request, InfraProvisioner $infra, PlatformActions $actions)
    {
        $countries = array_keys((array) config('zerobook.countries', []));

        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            'name' => ['required', 'string', 'max:191'],
            'plan' => ['required', 'string', 'exists:'.config('tenancy.database.central_connection').'.plans,tier'],
            'country' => ['required', 'in:'.implode(',', $countries)],
            // Phase 16 — the owner login created alongside the tenant.
            'owner_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ], [
            'subdomain.regex' => 'The subdomain must be a valid DNS label (lowercase letters, digits, hyphens).',
        ]);

        if (\App\Support\Subdomain::isReserved($data['subdomain']) || Tenant::whereKey($data['subdomain'])->exists()) {
            return back()->withErrors(['subdomain' => "The subdomain “{$data['subdomain']}” is reserved or already taken."])->withInput();
        }

        // Auto-create the Hostinger infrastructure (subdomain + database), then migrate/seed/activate.
        try {
            $tenant = $infra->provision($data['subdomain'], $data['name'], $data['plan'], $data['country']);
        } catch (Throwable $e) {
            return back()->withErrors(['subdomain' => 'Auto-provisioning failed: '.$e->getMessage()])->withInput();
        }

        // Create the owner login (the provisioner never does) and welcome them by email.
        $password = ($data['password'] ?? '') ?: \Illuminate\Support\Str::random(14);
        $owner = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => $data['owner_name'],
            'email' => $data['email'],
            'mobile' => $data['mobile'] ?? null,
            'password' => $password,        // hashed by the model cast
            'role' => 'owner',
            'verified_at' => now(),         // admin-created ⇒ can sign in immediately
        ]);

        $setPasswordUrl = $this->buildSetPasswordUrl($tenant, $owner);
        $mailed = $this->sendWelcomeEmail($tenant, $owner, $setPasswordUrl);

        $actions->log(Auth::guard('platform')->user(), 'provision', $tenant, [
            'meta' => ['plan' => $data['plan'], 'country' => $data['country'], 'manual' => true, 'auto_infra' => true, 'owner' => $owner->email],
        ]);

        $flash = "Tenant “{$tenant->id}” provisioned — subdomain + database created and activated. Owner: {$owner->email}. "
            .($mailed
                ? 'A welcome email with a set-password link has been sent to them.'
                : '⚠ The welcome email could NOT be sent — share this set-password link with them: '.$setPasswordUrl);

        if ($data['password'] ?? null) {
            $flash .= ' (The password you set also works for their first sign-in.)';
        }

        return redirect()->route('platform.tenant', $tenant->id)->with('flash', $flash);
    }

    /** The tenant's sign-in URL on its own subdomain. */
    private function tenantLoginUrl(Tenant $tenant): string
    {
        return 'https://'.$tenant->id.'.'.config('hostinger.website_domain', 'zerobook.in').'/login';
    }

    /**
     * A one-time "set your password" link on the TENANT's own subdomain.
     *
     * Uses the tenant password broker, so it is a normal single-use, expiring,
     * tenant-scoped reset token. Built by hand rather than with route(), because this
     * runs on the admin host and route() would emit an admin.<domain> URL.
     */
    private function buildSetPasswordUrl(Tenant $tenant, TenantUser $owner): string
    {
        $token = \Illuminate\Support\Facades\Password::broker('tenant_users')->createToken($owner);

        return 'https://'.$tenant->id.'.'.config('hostinger.website_domain', 'zerobook.in')
            .'/reset-password/'.$token.'?email='.urlencode($owner->email);
    }

    /**
     * Send the welcome email — sign-in URL + a set-password link, never a password.
     * Never fatal: the tenant already exists, so a mail failure must not roll anything
     * back; the caller surfaces the link in the flash instead.
     */
    private function sendWelcomeEmail(Tenant $tenant, TenantUser $owner, string $setPasswordUrl): bool
    {
        try {
            $owner->notify(new \App\Notifications\TenantWelcome(
                $tenant->name,
                $this->tenantLoginUrl($tenant),
                $owner->email,
                $setPasswordUrl,
                $tenant->plan?->name,
                (int) config('auth.passwords.tenant_users.expire', 60),
            ));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Phase 16 — auto-provision infrastructure for an EXISTING (stuck/provisioning) tenant:
     * create its subdomain + database via the Hostinger API, then migrate/seed/activate.
     */
    public function autoProvision(Request $request, Tenant $tenant, InfraProvisioner $infra, PlatformActions $actions)
    {
        $countries = array_keys((array) config('zerobook.countries', []));
        $data = $request->validate([
            'country' => ['required', 'in:'.implode(',', $countries)],
        ]);

        try {
            $infra->provision($tenant->id, $tenant->name, $tenant->plan?->tier ?? 'starter', $data['country']);
        } catch (Throwable $e) {
            return back()->withErrors(['auto' => 'Auto-provisioning failed: '.$e->getMessage()])->withInput();
        }

        $actions->log(Auth::guard('platform')->user(), 'auto-provision', $tenant, [
            'meta' => ['country' => $data['country']],
        ]);

        return redirect()->route('platform.tenant', $tenant->id)
            ->with('flash', "Infrastructure created — “{$tenant->id}” is now active.");
    }

    /**
     * Phase 16 — link a MANUALLY pre-created database to a tenant and activate it.
     *
     * For hosts (Hostinger shared) where the app cannot CREATE DATABASE: the operator
     * creates the schema in hPanel, then records its name (+ optional dedicated user /
     * password) here. Blank user/password → the tenant inherits the central DB
     * credentials (use when the SAME db user is assigned to the tenant schema in hPanel).
     */
    public function linkDatabase(Request $request, Tenant $tenant, TenantProvisioner $provisioner, PlatformActions $actions)
    {
        $countries = array_keys((array) config('zerobook.countries', []));

        $data = $request->validate([
            'db_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'db_username' => ['nullable', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'in:'.implode(',', $countries)],
        ], [
            'db_name.regex' => 'The database name may contain only letters, digits and underscores.',
        ]);

        // 1) Record the manually-created database's coordinates on the tenant.
        $tenant->setInternal('db_name', $data['db_name']);
        if (! empty($data['db_username'])) {
            $tenant->setInternal('db_username', $data['db_username']);
        }
        if (! empty($data['db_password'])) {
            $tenant->setInternal('db_password', $data['db_password']);
        }
        $tenant->save();

        // 2) Prove the database is reachable with these credentials before migrating.
        try {
            config(['database.connections.tenant_link_probe' => $tenant->database()->connection()]);
            DB::purge('tenant_link_probe');
            DB::connection('tenant_link_probe')->select('select 1');
        } catch (Throwable $e) {
            return back()->withErrors(['db_name' => 'Could not connect to that database with those credentials. Check the name, that the DB user is assigned to it in hPanel, and the password. ('.$e->getMessage().')'])->withInput();
        } finally {
            DB::purge('tenant_link_probe');
        }

        // 3) Adopt it — migrate + seed + regime + activate, without CREATE DATABASE.
        try {
            $provisioner->adoptExistingDatabase($tenant, $data['country']);
        } catch (Throwable $e) {
            return back()->withErrors(['db_name' => 'Connected, but building the tenant failed while migrating/seeding: '.$e->getMessage()])->withInput();
        }

        $actions->log(Auth::guard('platform')->user(), 'link-database', $tenant, [
            'meta' => ['db_name' => $data['db_name'], 'country' => $data['country'], 'dedicated_user' => ! empty($data['db_username'])],
        ]);

        return redirect()->route('platform.tenant', $tenant->id)
            ->with('flash', "Database “{$data['db_name']}” linked — “{$tenant->id}” is now active.");
    }

    /**
     * Phase 16 — one-click DB password change that stays in sync: change the REAL
     * password on Hostinger, then update ZeroBook's stored copy, then confirm the tenant
     * can reconnect. Hostinger is changed FIRST so a stored/real mismatch cannot outlive
     * a failed API call.
     */
    public function changeDatabasePassword(Request $request, Tenant $tenant, \App\Services\Hostinger\HostingerService $hostinger, PlatformActions $actions)
    {
        $data = $request->validate([
            'db_password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $dbName = $tenant->getInternal('db_name');
        if (! $dbName) {
            return back()->withErrors(['db_password' => 'This tenant has no linked database yet.']);
        }

        $new = ($data['db_password'] ?? '') ?: \Illuminate\Support\Str::random(24);

        // 1) Change the REAL MySQL password on Hostinger first.
        try {
            $hostinger->changeDatabasePassword($dbName, $new);
        } catch (Throwable $e) {
            return back()->withErrors(['db_password' => 'Hostinger rejected the password change (stored password left unchanged): '.$e->getMessage()]);
        }

        // 2) Update ZeroBook's stored copy to match.
        $tenant->setInternal('db_password', $new);
        $tenant->save();

        // 3) Confirm the tenant reconnects (the Hostinger change can take a moment to apply).
        config(['database.connections.tenant_pw_probe' => $tenant->database()->connection()]);
        $ok = false;
        for ($i = 0; $i < 12; $i++) {
            try {
                DB::purge('tenant_pw_probe');
                DB::connection('tenant_pw_probe')->select('select 1');
                $ok = true;
                break;
            } catch (Throwable $e) {
                usleep(2_500_000); // 2.5s
            }
        }
        DB::purge('tenant_pw_probe');

        $actions->log(Auth::guard('platform')->user(), 'db-password-change', $tenant, ['meta' => ['db_name' => $dbName]]);

        if (! $ok) {
            return redirect()->route('platform.tenant', $tenant->id)
                ->with('flash', 'Password changed on Hostinger and saved. The reconnect test has not confirmed yet — Hostinger can take a minute to apply it; the tenant should reconnect shortly.');
        }

        return redirect()->route('platform.tenant', $tenant->id)
            ->with('flash', 'Database password changed on Hostinger and synced — tenant reconnected successfully.');
    }

    /**
     * Phase 16 — create a tenant login (the manual/CLI provision path never creates one).
     * Stamped verified_at so the tenant login gate does not block an admin-created user.
     */
    public function createTenantUser(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['nullable', 'in:owner,member'],
            'send_welcome' => ['nullable'],
        ]);

        if (TenantUser::where('tenant_id', $tenant->id)->where('email', $data['email'])->exists()) {
            return back()->withErrors(['email' => 'That email already has a login for this tenant.'])->withInput();
        }

        $password = ($data['password'] ?? '') ?: \Illuminate\Support\Str::random(14);

        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'mobile' => $data['mobile'] ?? null,
            'password' => $password,        // hashed by the model cast
            'role' => $data['role'] ?? 'owner',
            'verified_at' => now(),         // admin-created ⇒ trusted, can sign in immediately
        ]);

        $mailed = null;
        $setPasswordUrl = null;
        if ($request->boolean('send_welcome')) {
            $setPasswordUrl = $this->buildSetPasswordUrl($tenant, $user);
            $mailed = $this->sendWelcomeEmail($tenant, $user, $setPasswordUrl);
        }

        $actions->log(Auth::guard('platform')->user(), 'tenant-user-create', $tenant, [
            'meta' => ['email' => $user->email, 'role' => $user->role, 'mailed' => $mailed],
        ]);

        $flash = "Login created for {$user->email}.";
        if ($mailed === true) {
            $flash .= ' A welcome email with a set-password link has been sent to them.';
        } elseif ($mailed === false) {
            $flash .= ' ⚠ The welcome email could NOT be sent — share this set-password link: '.$setPasswordUrl;
        } else {
            $flash .= " Password: {$password}  (copy it now; it is not shown again)";
        }

        return redirect()->route('platform.tenant', $tenant->id)->with('flash', $flash);
    }

    /**
     * Phase 16 — edit the tenant's details: company name + the owner's name / email /
     * mobile. (Password is changed from the reset control; plan + status have their own.)
     */
    public function updateDetails(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:191'],
            'owner_id' => ['nullable', 'integer'],
            'owner_name' => ['nullable', 'string', 'max:191'],
            'owner_email' => ['nullable', 'email', 'max:191'],
            'owner_mobile' => ['nullable', 'string', 'max:32'],
        ]);

        $tenant->name = $data['company_name'];
        $tenant->save();

        if (! empty($data['owner_id'])) {
            $owner = TenantUser::where('tenant_id', $tenant->id)->find($data['owner_id']);

            if ($owner) {
                $email = ($data['owner_email'] ?? '') ?: $owner->email;

                if ($email !== $owner->email
                    && TenantUser::where('tenant_id', $tenant->id)->where('email', $email)->exists()) {
                    return back()->withErrors(['owner_email' => 'That email already has a login for this tenant.'])->withInput();
                }

                $owner->name = ($data['owner_name'] ?? '') ?: $owner->name;
                $owner->email = $email;
                $owner->mobile = $data['owner_mobile'] ?? null;
                $owner->save();
            }
        }

        $actions->log(Auth::guard('platform')->user(), 'update-details', $tenant, [
            'meta' => ['company_name' => $tenant->name],
        ]);

        return back()->with('flash', 'Details updated.');
    }

    /** Phase 16 — platform-admin reset of a tenant user's password. */
    public function resetTenantUserPassword(Request $request, Tenant $tenant, TenantUser $user, PlatformActions $actions)
    {
        abort_unless($user->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $password = ($data['password'] ?? '') ?: \Illuminate\Support\Str::random(14);

        $user->forceFill([
            'password' => $password,                       // hashed by the model cast
            'verified_at' => $user->verified_at ?? now(),  // an admin-set password clears the unverified block
        ])->save();

        $actions->log(Auth::guard('platform')->user(), 'tenant-user-password-reset', $tenant, [
            'meta' => ['email' => $user->email],
        ]);

        return redirect()->route('platform.tenant', $tenant->id)
            ->with('flash', "Password reset for {$user->email} — new password: {$password}  (copy it now; it is not shown again)");
    }

    /**
     * Phase 16 — manual status override. Flips the status flag only; it does not touch
     * the database/subdomain. The offboarding lifecycle owns archived/purge_* — refuse
     * to hand-edit those so the closure schedule can't be left dangling.
     */
    public function setStatus(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        $allowed = ['provisioning', 'pending_verification', 'active', 'suspended', 'expired_trial', 'expired_subscription', 'cancelled'];

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', $allowed)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($tenant->isOffboarding() || $tenant->isClosed()) {
            return back()->withErrors(['status' => 'This account is in the offboarding lifecycle — use the Offboarding controls instead.']);
        }

        $from = $tenant->status;
        $tenant->status = $data['status'];
        $tenant->save();

        $actions->log(Auth::guard('platform')->user(), 'set-status', $tenant, [
            'meta' => ['from' => $from, 'to' => $data['status'], 'reason' => $data['reason'] ?? null],
        ]);

        return back()->with('flash', "Status changed: “{$from}” → “{$data['status']}”.");
    }

    public function suspend(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $actions->suspend(Auth::guard('platform')->user(), $tenant, $reason);

        return back()->with('flash', "“{$tenant->id}” suspended — its users can read but not post.");
    }

    public function reactivate(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        // A tenant in the offboarding lifecycle (suspended-offboarding / archived / …) must be
        // reactivated through OffboardingService, which clears the closure schedule and restores
        // an archived DB. The plain 14A reactivate only flips status, so it would leave
        // archive_scheduled_for / purge_scheduled_for dangling — refuse and point to the right control.
        if ($tenant->isOffboarding() || $tenant->isClosed()) {
            return back()->withErrors(['reactivate' => 'This account is being offboarded — reactivate it from the Offboarding lifecycle controls, not here.']);
        }

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $actions->reactivate(Auth::guard('platform')->user(), $tenant, $reason);

        return back()->with('flash', "“{$tenant->id}” reactivated — write access restored.");
    }

    public function extendTrial(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $actions->extendTrial(Auth::guard('platform')->user(), $tenant, (int) $data['days'], $data['reason'] ?? null);

        return back()->with('flash', "Trial extended by {$data['days']} days — now ends {$tenant->fresh()->trial_ends_at?->format('d-M-Y')}.");
    }

    public function changePlan(Request $request, Tenant $tenant, PlatformActions $actions)
    {
        $data = $request->validate([
            'tier' => ['required', 'string', 'exists:'.config('tenancy.database.central_connection').'.plans,tier'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $actions->changePlan(Auth::guard('platform')->user(), $tenant, $data['tier'], $data['reason'] ?? null);

        return back()->with('flash', "Plan changed to “{$data['tier']}”.");
    }

    /** POST /admin/tenants/{tenant}/impersonate — start a support session. */
    public function impersonate(Request $request, Tenant $tenant, ImpersonationService $impersonation)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'write' => ['nullable', 'boolean'],
        ], [
            'reason.required' => 'A reason is required — every impersonation is logged.',
        ]);

        if (! $tenant->isVerified() || $tenant->status === 'provisioning' || $tenant->status === 'pending_verification') {
            return back()->withErrors(['impersonate' => 'This tenant is not activated yet — nothing to impersonate.']);
        }

        $target = TenantUser::where('tenant_id', $tenant->id)
            ->orderByRaw("role = 'owner' desc")->orderBy('id')->first();

        if (! $target) {
            return back()->withErrors(['impersonate' => 'This tenant has no user to impersonate.']);
        }

        $url = $impersonation->start(
            Auth::guard('platform')->user(),
            $tenant,
            $target,
            (bool) ($data['write'] ?? false),
            $data['reason'],
        );

        return redirect()->away($url);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function knownStatuses(): array
    {
        return ['provisioning', 'pending_verification', 'active', 'suspended', 'expired_trial', 'cancelled'];
    }

    private function safeCompanyCount(Tenant $tenant): ?int
    {
        try {
            return $tenant->run(fn () => Company::count());
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Activity COUNTS only — never accounting values. Voucher count this month, the
     * tenant database size, company/user counts, and last-active time.
     */
    private function activitySummary(Tenant $tenant): array
    {
        $summary = [
            'vouchers_this_month' => null,
            'companies' => null,
            'storage_mb' => $this->databaseSizeMb($tenant),
            'last_active_at' => $tenant->last_active_at,
        ];

        try {
            $tenant->run(function () use (&$summary) {
                $summary['companies'] = Company::count();
                $summary['vouchers_this_month'] = Voucher::whereBetween('date', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ])->count();
            });
        } catch (Throwable $e) {
            // A not-yet-provisioned tenant has no DB to read — leave the nulls.
        }

        return $summary;
    }

    /** Tenant database size in MB, read from information_schema (no DB switch). */
    private function databaseSizeMb(Tenant $tenant): ?float
    {
        try {
            $central = config('tenancy.database.central_connection');
            $row = DB::connection($central)->selectOne(
                'SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = ?',
                [$tenant->database()->getName()],
            );

            return $row && $row->bytes ? round(((int) $row->bytes) / 1048576, 2) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
