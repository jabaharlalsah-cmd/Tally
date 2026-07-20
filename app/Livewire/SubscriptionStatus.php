<?php

namespace App\Livewire;

use App\Models\Payment;
use App\Models\Tenant;
use App\Support\TenantBilling;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Phase 14B — the tenant's subscription screen (on their subdomain).
 *
 * Shows the current plan, plan_ends_at / days remaining, the renewal banner, and the
 * customer's payment history, plus a "Record a payment" form. The screen is READ-ONLY
 * Livewire (no wire actions): the claim form is a standard multipart POST to
 * TenantSubscriptionController, so an expired tenant — who cannot use Livewire write
 * actions — can still submit a renewal, and file upload stays a plain browser form.
 */
#[Layout('components.layouts.plain')]
#[Title('Subscription — ZeroBook')]
class SubscriptionStatus extends Component
{
    public function render()
    {
        $tenant = Tenant::find(tenant('id'));
        $currency = TenantBilling::currency();

        return view('livewire.subscription-status', [
            'tenant' => $tenant,
            'plan' => $tenant?->plan,
            'currency' => $currency,
            'symbol' => TenantBilling::currencySymbol($currency),
            'plans' => TenantBilling::purchasablePlans($currency),
            'modes' => Payment::MODES,
            'payments' => Payment::with('plan')
                ->where('tenant_id', tenant('id'))
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }
}
