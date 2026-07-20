<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\CompanyFeature;
use App\Models\TdsReturnFiling;
use App\Models\Voucher;
use App\Services\Tds\Form26qException;
use App\Services\Tds\Form26qExporter;
use App\Services\Tds\Form26qStructuralValidator;
use App\Services\TdsService;
use Carbon\Carbon;
use Livewire\Component;
use Throwable;

/**
 * Phase 10B — the Form 26Q Returns screen. Pick a fiscal year, SEE each quarter's status
 * (draft / ready / filed), download the FVU-ready `.txt`, and record the acknowledgement
 * token after the portal accepts it. The status and preview are computed from the very
 * same exporter the download serves, so what a CA eyeballs is what gets filed.
 */
class Tds26qReturns extends Component
{
    use GuardsActiveCompany;

    public int $fyStart;

    public ?string $flash = null;

    public ?string $error = null;

    // Token recorder.
    public int $tokenQuarter = 1;

    public string $tokenNo = '';

    public string $receiptNo = '';

    public function mount(): void
    {
        $this->fyStart = Voucher::statutoryFyStartFor(Carbon::today());
    }

    public function updatedFyStart(): void
    {
        $this->flash = $this->error = null;
    }

    /** Serve one quarter's 26Q text as a download — refusing (with the reasons) if not filable. */
    public function download(int $quarter)
    {
        $this->flash = $this->error = null;
        try {
            $text = app(Form26qExporter::class)->record($this->fyStart, $quarter);
        } catch (Form26qException $e) {
            $this->error = 'This quarter is not ready to file: '.implode(' · ', $e->errors);

            return null;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return null;
        }

        $tan = activeCompany()?->tan ?: 'NOTAN';
        $name = "{$tan}-Q{$quarter}-{$this->fyStart}.txt";

        return response()->streamDownload(function () use ($text) {
            echo $text;
        }, $name, ['Content-Type' => 'text/plain']);
    }

    /** Record the token / provisional receipt the portal returned after the CA uploaded. */
    public function recordToken(): void
    {
        $this->flash = $this->error = null;
        $this->validate([
            'tokenQuarter' => ['required', 'integer', 'between:1,4'],
            'tokenNo' => ['required', 'string', 'max:15'],
            'receiptNo' => ['nullable', 'string', 'max:30'],
        ]);

        TdsReturnFiling::updateOrCreate(
            ['fy_start' => $this->fyStart, 'quarter' => $this->tokenQuarter],
            ['token_no' => trim($this->tokenNo), 'receipt_no' => $this->receiptNo ? trim($this->receiptNo) : null, 'filed_at' => now()],
        );

        $this->flash = "Recorded token for FY ".Voucher::statutoryFyLabel($this->fyStart)." Q{$this->tokenQuarter}.";
        $this->tokenNo = $this->receiptNo = '';
    }

    /** Status + headline figures for each of the four quarters. */
    private function quarters(): array
    {
        $exporter = app(Form26qExporter::class);
        $validator = app(Form26qStructuralValidator::class);
        $tds = app(TdsService::class);
        $filings = TdsReturnFiling::where('fy_start', $this->fyStart)->get()->keyBy('quarter');

        $out = [];
        foreach ([1, 2, 3, 4] as $q) {
            [$from, $to] = Form26qExporter::quarterRange($this->fyStart, $q);
            $summary = $tds->summary($from, $to);
            $tdsPaise = $summary['total_deducted_paise'] ?? 0;

            $status = 'empty';
            $issues = [];
            $records = null;
            if ($tdsPaise > 0) {
                try {
                    $text = $exporter->record($this->fyStart, $q);
                    $val = $validator->validate($text);
                    $status = $val['ok'] ? 'ready' : 'draft';
                    $records = $val['counts'];
                } catch (Form26qException $e) {
                    $status = 'draft';
                    $issues = $e->errors;
                } catch (Throwable $e) {
                    $status = 'draft';
                    $issues = [$e->getMessage()];
                }
            }
            $filing = $filings->get($q);
            if ($filing && $filing->token_no) {
                $status = 'filed';
            }

            $out[] = [
                'quarter' => $q,
                'label' => 'Q'.$q,
                'months' => $from->format('M').'–'.$to->format('M Y'),
                'tds' => number_format($tdsPaise / 100, 2),
                'has_tds' => $tdsPaise > 0,
                'status' => $status,
                'records' => $records,
                'issues' => $issues,
                'token' => $filing?->token_no,
                'filed_at' => $filing?->filed_at?->format('d-M-Y'),
            ];
        }

        return $out;
    }

    public function render()
    {
        $tds = app(TdsService::class);

        return view('livewire.tds-26q-returns', [
            'quarters' => $this->quarters(),
            'fyLabel' => Voucher::statutoryFyLabel($this->fyStart),
            'enabled' => $tds->enabled(),
            'hasProfile' => (bool) activeCompany()?->tan,
        ]);
    }
}
