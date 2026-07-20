<?php

namespace App\Services\Tds;

/**
 * Phase 10B — which Form 26Q spec era a fiscal year belongs to.
 *
 * The e-TDS file format changed wholesale for Tax Year 2026-27, alongside the Income Tax
 * Act 2025 regime that Phase 10A already handles (the old 194-series → Section 393). So a
 * 26Q export has two possible formats:
 *
 *   • CURRENT — Form Number 140, `form-26q-file-format-current.xlsx`, validated by
 *     FVU 1.1. In force for fiscal years starting 2026 (FY 2026-27) onward.
 *   • HISTORICAL — 26Q file format v7.8, `form-26q-file-format-historical.xls`, validated
 *     by FVU 9.5. For fiscal years up to and including 2025 (FY 2025-26).
 *
 * The resolver picks the era from `--fy-start`. ZeroBook itself only ever holds Section-393
 * data (Phase 10A launched in FY 2026-27), so the historical builder has no data to run on;
 * a pre-2026 export is REFUSED with the spec citation rather than emitting the wrong format.
 * The switch is real and tested — the resolver returns the right era, spec file and FVU for
 * either year — so when a book eventually carries pre-2026 vouchers the historical builder
 * is the only piece to add.
 */
class SpecResolver
{
    public const ERA_CURRENT = 'current';

    public const ERA_HISTORICAL = 'historical';

    /** The first fiscal-year start governed by the current (Form 140) format. */
    public const CURRENT_FROM_FY = 2026;

    public function era(int $fyStart): string
    {
        return $fyStart >= self::CURRENT_FROM_FY ? self::ERA_CURRENT : self::ERA_HISTORICAL;
    }

    public function isCurrent(int $fyStart): bool
    {
        return $this->era($fyStart) === self::ERA_CURRENT;
    }

    /**
     * The full descriptor for a fiscal year's era — spec file, FVU directory + jar, and the
     * form/version metadata. Paths are repo-relative under _docs/tds-schemas/.
     */
    public function descriptor(int $fyStart): array
    {
        if ($this->isCurrent($fyStart)) {
            return [
                'era' => self::ERA_CURRENT,
                'form_number' => '140',
                'fvu_version' => '1.1',
                'spec_file' => '_docs/tds-schemas/form-26q-file-format-current.xlsx',
                'fvu_dir' => '_docs/tds-schemas/fvu-current/TDS_STANDALONE_FVU_1.1',
                'fvu_jar' => 'TDS_STANDALONE_FVU_1.1.jar',
            ];
        }

        return [
            'era' => self::ERA_HISTORICAL,
            'form_number' => '26Q',
            'fvu_version' => '9.5',
            'spec_file' => '_docs/tds-schemas/form-26q-file-format-historical.xls',
            'fvu_dir' => '_docs/tds-schemas/fvu-historical/TDS_STANDALONE_FVU_9.5',
            'fvu_jar' => 'TDS_STANDALONE_FVU_9.5.jar',
        ];
    }
}
