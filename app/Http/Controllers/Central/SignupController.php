<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Tenancy\SignupService;
use App\Support\Subdomain;
use App\Support\TenantUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Phase 14A — the public self-signup surface (central domain / www).
 *
 * A single-page form provisions a fresh, fully-isolated tenant (its own database,
 * migrated + seeded, with the chosen tax regime), creates the admin owner, and emails a
 * confirmation link. The admin cannot log in until they follow that link — which flips
 * the tenant to 'active' and signs them into their subdomain.
 *
 * The write endpoints are rate-limited at the route (signup 5/IP/hour, availability
 * 60/IP/minute) — real signups are rare, spam is common.
 */
class SignupController extends Controller
{
    /** GET /signup — the form. */
    public function create()
    {
        return view('central.signup', [
            'countries' => config('zerobook.countries'),
            'trialDays' => (int) config('zerobook.trial_days', 30),
        ]);
    }

    /** POST /signup — provision the tenant (transactional) and send verification. */
    public function store(Request $request, SignupService $signup)
    {
        $countries = array_keys((array) config('zerobook.countries', []));

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:191'],
            'subdomain' => ['required', 'string', 'min:'.Subdomain::MIN_LENGTH, 'max:'.Subdomain::maxLength(), 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            'country' => ['required', 'in:'.implode(',', $countries)],
            'admin_name' => ['required', 'string', 'max:191'],
            'admin_email' => ['required', 'email', 'max:191'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'subdomain.regex' => 'Use lowercase letters, digits and hyphens only.',
            'admin_password.confirmed' => 'The two passwords do not match.',
        ]);

        $slug = Subdomain::normalize($data['subdomain']);

        // Re-check availability server-side (the live check is a convenience, not a gate).
        $availability = Subdomain::availability($slug);
        if (! $availability['available']) {
            return back()->withErrors(['subdomain' => $availability['reason']])->withInput();
        }

        try {
            $result = $signup->signup([
                'subdomain' => $slug,
                'company_name' => $data['company_name'],
                'country' => $data['country'],
                'admin_name' => $data['admin_name'],
                'admin_email' => strtolower(trim($data['admin_email'])),
                'admin_password' => $data['admin_password'],
            ]);
        } catch (Throwable $e) {
            report($e); // provisioning already rolled back — the subdomain is free again
            $again = Subdomain::availability($slug);

            return back()->withErrors([
                'subdomain' => $again['available']
                    ? 'Sorry — something went wrong creating your account. Please try again.'
                    : $again['reason'],
            ])->withInput();
        }

        return redirect()->route('signup.check-email')
            ->with('signup_email', $result['admin']->email)
            ->with('signup_subdomain', $result['tenant']->id);
    }

    /** GET /signup/check-email — the "we sent you a link" page. */
    public function checkEmail(Request $request)
    {
        // Only meaningful right after a signup; otherwise send them to the form.
        if (! $request->session()->has('signup_email')) {
            return redirect()->route('signup');
        }

        return view('central.check-email', [
            'email' => $request->session()->get('signup_email'),
            'subdomain' => $request->session()->get('signup_subdomain'),
        ]);
    }

    /**
     * GET /api/subdomain-available?slug=… — the live availability probe.
     * Rate-limited at the route (60/IP/min).
     */
    public function subdomainAvailable(Request $request)
    {
        $slug = (string) $request->query('slug', '');
        $result = Subdomain::availability($slug);

        return response()->json([
            'slug' => Subdomain::normalize($slug),
            'available' => $result['available'],
            'reason' => $result['reason'],
        ]);
    }

    /**
     * GET /verify/{id}/{hash} — email confirmation (temporary signed URL, host-independent).
     * Marks the user + tenant verified, activates the tenant, and hands off to the
     * tenant subdomain already logged in.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $user = TenantUser::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            abort(403, 'This verification link is invalid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $tenant = Tenant::find($user->tenant_id);
        if ($tenant) {
            $tenant->verified_at = $tenant->verified_at ?? now();
            if ($tenant->status === 'pending_verification') {
                $tenant->status = 'active';
            }
            $tenant->save();
        }

        // Hand off to the tenant subdomain, signed, to log the user in there.
        $relative = URL::temporarySignedRoute(
            'auth.consume',
            now()->addMinutes(5),
            ['user' => $user->id],
            absolute: false,
        );

        return redirect()->away(TenantUrl::forTenant($user->tenant_id, $relative));
    }
}
