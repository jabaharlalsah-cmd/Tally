<?php

namespace App\Services\Tenancy;

/**
 * Phase 14A — orchestrates a public self-signup.
 *
 * A thin seam over TenantProvisioner so the signup controller and the prove harness
 * share one entry point: provision the tenant (transactional, with rollback) and send
 * the admin their email-verification link. Returns ['tenant' => Tenant, 'admin' =>
 * TenantUser].
 *
 * Input validation lives in the controller (SignupController) / the availability rules
 * (App\Support\Subdomain); this service assumes clean, already-validated data.
 */
class SignupService
{
    public function __construct(private TenantProvisioner $provisioner)
    {
    }

    /**
     * @param  array{subdomain:string,company_name:string,country:string,admin_name:string,admin_email:string,admin_password:string}  $data
     * @return array{tenant:\App\Models\Tenant,admin:\App\Models\TenantUser}
     */
    public function signup(array $data): array
    {
        $result = $this->provisioner->provisionForSignup($data);

        // The verification email is sent synchronously AFTER provisioning has committed, so
        // a mailer failure (SMTP down, bad credentials, transient network) is NOT covered by
        // provisionForSignup's rollback. Without this guard the tenant would be left fully
        // provisioned but unverifiable — the admin can't log in (unverified), can't re-signup
        // (subdomain taken), and never got a link. Tear the tenant down on a send failure so
        // invariant 2 holds: a user-visible signup failure leaves the subdomain free to retry.
        try {
            $result['admin']->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            $this->provisioner->teardown($result['tenant']->id);

            throw $e;
        }

        return $result;
    }
}
