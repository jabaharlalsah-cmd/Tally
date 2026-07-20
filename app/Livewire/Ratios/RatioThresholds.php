<?php

namespace App\Livewire\Ratios;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\RatioThreshold;
use App\Services\RatioService;
use Livewire\Component;

/**
 * Phase 15B — the per-company health-threshold settings (reached via F12 on the dashboard).
 * Editing a band and saving re-colours the dashboard on the next load. Never asserts an absolute
 * "unhealthy" — the bands are transparent and industry-adjustable.
 */
class RatioThresholds extends Component
{
    use GuardsActiveCompany;

    /** ratio_key => ['green_min' => string, 'amber_min' => string] */
    public array $bands = [];
    public string $flash = '';

    public function mount(): void
    {
        $map = RatioThreshold::mapForCompany(RatioService::DEFAULT_THRESHOLDS);
        foreach (RatioService::RATIOS as $key => $meta) {
            if ($meta[1] === 'budget') {
                continue;
            }
            $t = $map[$key] ?? null;
            $this->bands[$key] = [
                'green_min' => $t && $t->green_min !== null ? (string) $this->trim($t->green_min) : '',
                'amber_min' => $t && $t->amber_min !== null ? (string) $this->trim($t->amber_min) : '',
            ];
        }
    }

    public function save(): void
    {
        foreach ($this->bands as $key => $b) {
            if (! isset(RatioService::RATIOS[$key])) {
                continue;
            }
            RatioThreshold::updateOrCreate(
                ['ratio_key' => $key],
                [
                    'green_min' => $b['green_min'] === '' ? null : (float) $b['green_min'],
                    'amber_min' => $b['amber_min'] === '' ? null : (float) $b['amber_min'],
                ]
            );
        }
        $this->flash = 'Thresholds saved. The dashboard will use the new colour bands.';
    }

    public function restoreDefaults(): void
    {
        foreach (RatioService::DEFAULT_THRESHOLDS as $key => $d) {
            if (! isset(RatioService::RATIOS[$key]) || RatioService::RATIOS[$key][1] === 'budget') {
                continue;
            }
            $this->bands[$key] = [
                'green_min' => isset($d['green_min']) ? (string) $this->trim($d['green_min']) : '',
                'amber_min' => isset($d['amber_min']) ? (string) $this->trim($d['amber_min']) : '',
            ];
        }
        $this->flash = 'Reverted to industry-neutral defaults (not yet saved — press Save to keep).';
    }

    private function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    public function render()
    {
        $rows = [];
        foreach (RatioService::RATIOS as $key => $meta) {
            if ($meta[1] === 'budget') {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => $meta[0],
                'group' => $meta[1],
                'direction' => $meta[2],
                'unit' => $meta[3],
            ];
        }

        return view('livewire.ratios.thresholds', ['rows' => $rows]);
    }
}
