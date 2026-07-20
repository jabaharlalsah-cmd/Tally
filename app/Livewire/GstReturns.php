<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\GstReturnFiling;
use App\Services\Gst\GstnFormat;
use App\Services\Gst\GstReturnService;
use App\Support\GstReturnExport;
use Carbon\Carbon;
use Livewire\Component;
use Throwable;

/**
 * Phase 9A — the GST Returns screen: pick a period, SEE what is in the return, then
 * download the portal-ready JSON. The preview is computed from the very same generated
 * document the download serves, so what a CA eyeballs is what gets filed.
 */
class GstReturns extends Component
{
    use GuardsActiveCompany;

    public string $period = '';

    public string $arn = '';

    public string $arnType = 'gstr1';

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->period = GstReturnExport::lastCompletedPeriod();
    }

    public function updatedPeriod(): void
    {
        $this->flash = null;
        $this->error = null;
    }

    private function svc(): GstReturnService
    {
        return app(GstReturnService::class);
    }

    /** Serve the GSTR-1 JSON as a download (compact, exactly as the portal wants it). */
    public function downloadGstr1()
    {
        return $this->download('gstr1');
    }

    /** Serve the GSTR-3B JSON as a download. */
    public function downloadGstr3b()
    {
        return $this->download('gstr3b');
    }

    private function download(string $type)
    {
        $this->flash = null;
        $this->error = null;
        try {
            $json = $type === 'gstr1' ? $this->svc()->gstr1($this->period) : $this->svc()->gstr3b($this->period);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return null;
        }

        $body = GstReturnExport::encode($json);
        $name = GstReturnExport::filename($type, $json['gstin'], $this->period);

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $name, ['Content-Type' => 'application/json']);
    }

    /** Record the Acknowledgement Reference Number the portal returned after upload. */
    public function recordArn(): void
    {
        $this->flash = null;
        $this->error = null;

        $arn = trim($this->arn);
        if ($arn === '') {
            $this->error = 'Enter the ARN the GST portal returned after you uploaded the JSON.';

            return;
        }
        if (! in_array($this->arnType, ['gstr1', 'gstr3b'], true)) {
            $this->error = 'Choose which return the ARN belongs to.';

            return;
        }

        GstReturnFiling::record($this->period, $this->arnType, $arn);
        $this->arn = '';
        $this->flash = 'Recorded ARN for '.GstReturnFiling::TYPES[$this->arnType].' · '.GstnFormat::periodLabel($this->period);
    }

    /** The last 12 completed return periods, newest first. */
    private function periodOptions(): array
    {
        $out = [];
        $cursor = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $p = $cursor->format('mY');
            $out[$p] = $cursor->format('F Y');
            $cursor->subMonthNoOverflow();
        }

        return $out;
    }

    public function render()
    {
        $preview = null;
        $error = $this->error;

        try {
            $preview = $this->svc()->preview($this->period);
        } catch (Throwable $e) {
            $error = $error ?? $e->getMessage();
        }

        return view('livewire.gst-returns', [
            'preview' => $preview,
            'renderError' => $error,
            'periods' => $this->periodOptions(),
            'filings' => GstReturnFiling::where('period', $this->period)->get()->keyBy('return_type'),
            'periodLabel' => GstnFormat::periodLabel($this->period),
        ]);
    }
}
