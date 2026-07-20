<?php

namespace App\Livewire\Ratios;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Voucher;
use App\Services\RatioService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * Phase 15B — the Ratio Dashboard. Four sections (Liquidity, Solvency, Profitability, Efficiency)
 * plus budget ratios when 15A is on, each ratio with value, prior-period delta, health colour and
 * a 12-point sparkline. F2 sets the as-of date; Enter drills a ratio to its inputs; F12 opens the
 * thresholds settings.
 */
class RatioDashboard extends Component
{
    use GuardsActiveCompany;

    public ?string $asOf = null;

    public function mount(): void
    {
        $this->asOf = now()->toDateString();
    }

    public function render()
    {
        $svc = app(RatioService::class);
        $asOf = $this->clampedAsOf();

        $data = $svc->dashboard($asOf);
        $grid = $svc->trendGrid($asOf);

        $sections = [];
        foreach (['liquidity', 'solvency', 'profitability', 'efficiency', 'budget'] as $g) {
            $rows = [];
            foreach ($data[$g] as $r) {
                $r['display'] = $this->fmtValue($r);
                $r['delta_display'] = $this->fmtDelta($r);
                $r['spark'] = $this->sparkline($grid[$r['key']] ?? []);
                $rows[] = $r;
            }
            $sections[$g] = $rows;
        }

        return view('livewire.ratios.dashboard', [
            'sections' => $sections,
            'asOfLabel' => $asOf->format('d-M-Y'),
            'sectionTitles' => [
                'liquidity' => 'Liquidity', 'solvency' => 'Solvency / Leverage',
                'profitability' => 'Profitability', 'efficiency' => 'Efficiency', 'budget' => 'Budget',
            ],
        ]);
    }

    private function clampedAsOf(): Carbon
    {
        // A future as-of makes no sense for "current health" — never look past today.
        $asOf = Carbon::parse($this->asOf);
        $today = Carbon::today();

        return $asOf->gt($today) ? $today : $asOf;
    }

    /** Ratio value + unit, or "N/A". */
    private function fmtValue(array $r): string
    {
        if ($r['value'] === null) {
            return 'N/A';
        }
        $v = rtrim(rtrim(number_format($r['value'], 2), '0'), '.');

        return match ($r['unit']) {
            '%' => $v.'%',
            'd' => $v.' days',
            default => $v.'×',
        };
    }

    /** Signed delta vs the prior period (Unicode minus), or an em dash. */
    private function fmtDelta(array $r): string
    {
        if ($r['delta'] === null) {
            return '—';
        }
        $sign = $r['delta'] > 0 ? '+' : ($r['delta'] < 0 ? '−' : '');
        $mag = rtrim(rtrim(number_format(abs($r['delta']), 2), '0'), '.');

        return $sign.$mag;
    }

    /**
     * Build an SVG polyline points string (120×30 viewport, y-inverted) from a ratio's trend.
     * Nulls are skipped; fewer than 2 points yields an empty string (no line drawn).
     */
    private function sparkline(array $points): string
    {
        $total = count($points);
        if ($total < 2) {
            return '';
        }
        $vals = array_filter(array_map(fn ($p) => $p['value'], $points), fn ($v) => $v !== null);
        if (count($vals) < 2) {
            return '';
        }
        $min = min($vals);
        $max = max($vals);
        $span = ($max - $min) ?: 1;
        $w = 116;
        $h = 26;
        $out = [];
        // x from the ORIGINAL month index so a null (N/A) month leaves a real time gap, not a
        // collapsed axis that plots non-contiguous months as if adjacent.
        foreach ($points as $i => $p) {
            if ($p['value'] === null) {
                continue;
            }
            $x = 2 + ($i / ($total - 1)) * $w;
            $y = 2 + $h - (($p['value'] - $min) / $span) * $h;
            $out[] = round($x, 1).','.round($y, 1);
        }

        return implode(' ', $out);
    }
}
