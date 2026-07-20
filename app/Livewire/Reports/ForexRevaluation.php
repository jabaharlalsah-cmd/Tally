<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\VoucherScreen;
use App\Services\BalanceService;
use App\Services\ForexService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 11 — the Forex Revaluation report. As of a date, for each open foreign-currency
 * bill: its remaining foreign amount, the base it is booked at, what it is worth at the
 * current rate, and the UNREALISED gain/loss. The report only COMPUTES; the user reviews
 * and, on a click, posts the balancing revaluation Journal through the normal path (the
 * balance gate applies) — never auto-posted.
 */
class ForexRevaluation extends Component
{
    use GuardsActiveCompany;

    public ?string $asOf = null;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->asOf = Carbon::today()->toDateString();
    }

    /** Post the computed revaluation Journal (after the user has reviewed the figures). */
    public function postRevaluation(): void
    {
        $this->flash = $this->error = null;
        $forex = app(ForexService::class);
        $asOf = Carbon::parse($this->asOf);
        $jl = $forex->revaluationJournalLines($asOf);

        if (! $jl['has_data']) {
            $this->error = 'There is nothing to revalue as of this date.';

            return;
        }

        try {
            (new VoucherScreen())->post([
                'type' => 'journal',
                'date' => $asOf->toDateString(),
                'narration' => 'Forex revaluation as of '.$asOf->format('d-M-Y'),
                'lines' => $jl['lines'],
                'forex_revaluation' => true,
            ]);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->flash = 'Revaluation journal posted for '.$asOf->format('d-M-Y').'. Reverse it next period if you revalue afresh.';
    }

    public function render()
    {
        $forex = app(ForexService::class);
        $reval = $forex->revaluation(Carbon::parse($this->asOf));

        return view('livewire.reports.forex-revaluation', [
            'reval' => $reval,
            'enabled' => $forex->enabled(),
        ]);
    }
}
