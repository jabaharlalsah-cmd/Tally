<?php

namespace App\Livewire;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 16A — Settings → API Keys → Activity. The request log for ONE key.
 *
 * Answers the question a customer actually has when an integration misbehaves: is it reaching
 * us at all, what is it asking for, and what are we telling it? Request count, error rate, and
 * the recent calls with their X-Request-Id — which is the id they quote to support.
 *
 * Owner-only, matching ApiKeysList: the log reveals the shape of a tenant's integration traffic.
 *
 * No request or response bodies are shown because none are stored — only a SHA-256 of the
 * request body (see the migration). The digest is displayed truncated purely as a "same payload
 * or not?" fingerprint.
 */
#[Layout('components.layouts.plain')]
#[Title('API Key Activity — ZeroBook')]
class ApiKeyActivity extends Component
{
    use WithPagination;

    public ApiKey $apiKey;

    /** Route-model bound from /settings/api-keys/{apiKey}/activity. */
    public function mount(ApiKey $apiKey): void
    {
        $user = Auth::guard('tenant')->user();

        if (! $user || $user->role !== ApiKeysList::ADMIN_ROLE) {
            abort(403, 'Only an account owner can view API key activity.');
        }

        $this->apiKey = $apiKey;
    }

    public function render()
    {
        $base = ApiRequestLog::where('api_key_id', $this->apiKey->id);

        $total = (clone $base)->count();
        $errors = (clone $base)->where('response_status', '>=', 400)->count();

        return view('livewire.api-key-activity', [
            'total' => $total,
            'errors' => $errors,
            'errorRate' => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
            'lastDay' => (clone $base)->where('created_at', '>=', now()->subDay())->count(),
            'rows' => (clone $base)->orderByDesc('id')->paginate(25),
        ]);
    }
}
