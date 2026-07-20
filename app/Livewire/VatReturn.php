<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\VatReturnFiling;
use App\Services\Vat\NepalVatReturnService;
use App\Support\NepalDate;
use App\Support\VatReturnExport;
use Livewire\Component;
use Throwable;

/**
 * Phase 9B — the Nepal VAT Return screen.
 *
 * The IRD taxpayer portal accepts no return file, so this screen's job is to remove the
 * *re-computation* from filing, not the typing: it lays out Schedule 10 box by box, in
 * the form's own order and with the form's own Devanagari labels, so the figures can be
 * transcribed straight into the portal's E-VAT Return Entry form.
 *
 * The preview and the box table are both read off the SAME generated document, so what
 * is displayed can never drift from what is downloaded.
 */
class VatReturn extends Component
{
    use GuardsActiveCompany;

    public string $period = '';

    public int $carryForward = 0;

    public string $submissionRef = '';

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->period = NepalDate::lastCompletedPeriod();
    }

    public function updatedPeriod(): void
    {
        $this->flash = null;
        $this->error = null;
    }

    public function updatedCarryForward(): void
    {
        $this->flash = null;
        $this->error = null;
        if ($this->carryForward < 0) {
            $this->carryForward = 0;
        }
    }

    private function svc(): NepalVatReturnService
    {
        return app(NepalVatReturnService::class);
    }

    /** Serve the transcription document (pretty-printed, Devanagari unescaped). */
    public function download()
    {
        $this->flash = null;
        $this->error = null;
        try {
            $return = $this->svc()->return($this->period, $this->carryForward);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return null;
        }

        $body = VatReturnExport::encode($return);
        $name = VatReturnExport::filename($return['header']['pan'], $this->period);

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $name, ['Content-Type' => 'application/json']);
    }

    /** Record the reference the IRD portal returned after the return was submitted. */
    public function recordSubmissionRef(): void
    {
        $this->flash = null;
        $this->error = null;

        $ref = trim($this->submissionRef);
        if ($ref === '') {
            $this->error = 'Enter the submission reference the IRD portal returned after you filed.';

            return;
        }

        VatReturnFiling::record($this->period, $ref);
        $this->submissionRef = '';
        $this->flash = 'Recorded submission reference for '.NepalDate::periodLabel($this->period);
    }

    public function render()
    {
        $return = null;
        $preview = null;
        $error = $this->error;

        try {
            $return = $this->svc()->return($this->period, $this->carryForward);
            $preview = $this->svc()->preview($this->period, $this->carryForward);
        } catch (Throwable $e) {
            $error = $error ?? $e->getMessage();
        }

        return view('livewire.vat-return', [
            'return' => $return,
            'preview' => $preview,
            'renderError' => $error,
            'periods' => NepalDate::recentPeriods(12),
            'filing' => VatReturnFiling::where('period', $this->period)->first(),
        ]);
    }
}
