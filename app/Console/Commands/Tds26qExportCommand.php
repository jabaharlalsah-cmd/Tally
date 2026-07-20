<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Console\Concerns\RunsInTenantContext;
use App\Models\Voucher;
use App\Services\Tds\Form26qException;
use App\Services\Tds\Form26qExporter;
use App\Services\Tds\Form26qStructuralValidator;
use App\Services\Tds\SpecResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 10B — write a tenant's Form 26Q quarterly return as a portal-ready `.txt`.
 *
 *   php artisan zerobook:26q-export --tenant=acme --fy-start=2026 --quarter=1 --output=26q.txt
 *
 * The file goes to the CA, who runs it through the official FVU (with their real .csi
 * challan file, downloaded from TIN) to produce the final .fvu for upload. Refuses to run
 * against the central database, refuses without the deductor's filing identity, and refuses
 * a pre-2026 (historical-format) request with the spec citation.
 */
class Tds26qExportCommand extends Command
{
    use ResolvesActiveCompany;
    use RunsInTenantContext;

    protected $signature = 'zerobook:26q-export
        {--tenant= : the tenant subdomain whose books to export}
        {--company= : REQUIRED — the company (slug or id) whose books to export}
        {--fy-start= : the fiscal-year START year, e.g. 2026 for FY 2026-27}
        {--quarter= : the quarter 1-4 (Q1=Apr-Jun, Q2=Jul-Sep, Q3=Oct-Dec, Q4=Jan-Mar)}
        {--output= : where to write the .txt (default: storage/app/tds-returns/<TAN>-Q<n>-<fyStart>.txt)}';

    protected $description = "Export a tenant's Form 26Q quarterly TDS return as an FVU-ready text file";

    public function handle(): int
    {
        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        // Phase 12A — a 26Q export writes/reads ONE company's books. Refuse to guess:
        // the tenant may hold many companies, and a return scoped to the wrong
        // one is silent data corruption. --company= is mandatory.
        if (trim((string) $this->option('company')) === '') {
            $this->error('Refusing to run without --company= — this tenant may hold multiple companies.');
            $this->line('Pass --company=<slug or id> to name the company whose books this command touches.');

            return self::FAILURE;
        }


        return $scope(function () {
            if (! $this->resolveActiveCompany()) {
                return self::FAILURE;
            }

            return $this->export();
        });
    }

    private function export(): int
    {
        $fyStart = (int) $this->option('fy-start');
        $quarter = (int) $this->option('quarter');

        if ($fyStart < 2000 || $quarter < 1 || $quarter > 4) {
            $this->error('Provide --fy-start=<year> and --quarter=1..4 (e.g. --fy-start=2026 --quarter=1).');

            return self::FAILURE;
        }

        // The historical-format era (pre-2026) is recognised but not built — ZeroBook has
        // no pre-2026 TDS data (Phase 10A launched under the Section 393 regime).
        $resolver = app(SpecResolver::class);
        if (! $resolver->isCurrent($fyStart)) {
            $d = $resolver->descriptor($fyStart);
            $this->error(sprintf(
                'FY %s falls in the historical e-TDS era (%s / FVU %s). ZeroBook books begin in FY 2026-27 '
                .'(Section 393 regime), so there is no pre-2026 TDS data to export.',
                Voucher::statutoryFyLabel($fyStart), $d['spec_file'], $d['fvu_version'],
            ));

            return self::FAILURE;
        }

        try {
            $text = app(Form26qExporter::class)->record($fyStart, $quarter);
        } catch (Form26qException $e) {
            $this->error("The 26Q for FY {$fyStart} Q{$quarter} cannot be filed as-is:");
            foreach ($e->errors as $err) {
                $this->line('  • '.$err);
            }
            $this->line('');
            $this->line('Fix these in ZeroBook, then export again — the file is not written until it is clean.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Structural conformance gate (stands in for the FVU on synthetic data).
        $val = app(Form26qStructuralValidator::class)->validate($text);
        if (! $val['ok']) {
            $this->error('Internal structural check failed (this is a bug — please report):');
            foreach ($val['issues'] as $is) {
                $this->line('  • '.$is);
            }

            return self::FAILURE;
        }

        $tan = activeCompany()?->tan ?: 'NOTAN';
        $path = (string) $this->option('output');
        if ($path === '') {
            $rel = "tds-returns/{$tan}-Q{$quarter}-{$fyStart}.txt";
            Storage::disk('local')->put($rel, $text);
            $path = Storage::disk('local')->path($rel);
        } else {
            file_put_contents($path, $text);
        }

        $c = $val['counts'];
        $this->info("Form 26Q written: {$path}");
        $this->line("  FY {$fyStart}-".substr((string) ($fyStart + 1), -2)." Q{$quarter} · Form 140 · FVU 1.1");
        $this->line("  Records: {$c['FH']} FH · {$c['BH']} BH · {$c['CD']} CD · {$c['DD']} DD");
        $this->line('  Structurally conformant to the published spec.');
        $this->line('');
        $this->line('Next: your CA validates this with the official FVU (using the .csi from TIN → Challan Status');
        $this->line('Inquiry) to produce the .fvu, then uploads it to the e-filing portal and records the token.');

        return self::SUCCESS;
    }
}
