<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Console\Concerns\RunsInTenantContext;
use App\Services\Gst\GstnFormat;
use App\Services\Gst\GstReturnService;
use App\Support\GstReturnExport;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 9A — write a tenant's GSTR-1 return for a period as a portal-ready JSON file.
 *
 *   php artisan zerobook:gstr1-export --tenant=acme --period=042026 --output=gstr1.json
 *
 * Refuses to run against the CENTRAL database (accounting data lives only in tenant DBs),
 * and refuses to export at all without a company GSTIN.
 */
class Gstr1ExportCommand extends Command
{
    use ResolvesActiveCompany;
    use RunsInTenantContext;

    protected $signature = 'zerobook:gstr1-export
        {--tenant= : the tenant subdomain whose books to export}
        {--company= : REQUIRED — the company (slug or id) whose books to export}
        {--period= : the return period as MMYYYY, e.g. 042026 (default: last completed month)}
        {--output= : where to write the JSON (default: gstr1-<gstin>-<period>.json in the cwd)}';

    protected $description = "Export a tenant's GSTR-1 (outward supplies) as a GSTN-schema JSON file";

    public function handle(): int
    {
        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        // Phase 12A — a GSTR-1 export writes/reads ONE company's books. Refuse to guess:
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
        $period = (string) ($this->option('period') ?: GstReturnExport::lastCompletedPeriod());

        try {
            GstnFormat::assertPeriod($period);
            $svc = app(GstReturnService::class);
            $json = $svc->gstr1($period);
            $preview = $svc->preview($period);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: GstReturnExport::filename('gstr1', $json['gstin'], $period));
        $bytes = GstReturnExport::write($path, $json);

        $g = $preview['gstr1'];
        $this->info('GSTR-1 · '.GstnFormat::periodLabel($period).' · GSTIN '.$json['gstin']);
        $this->line('');
        $this->table(['Section', 'Count'], [
            ['B2B invoices (to '.$g['b2b_parties'].' registered '.Str::plural('party', $g['b2b_parties']).')', $g['b2b_invoices']],
            ['B2CL invoices', $g['b2cl_invoices']],
            ['B2CS summary rows', $g['b2cs_rows']],
            ['CDNR credit notes', $g['cdnr_notes']],
            ['CDNUR credit notes', $g['cdnur_notes']],
            ['HSN summary rows', $g['hsn_rows']],
            ['Document ranges', $g['doc_ranges']],
        ]);
        $this->line('  Total taxable value : '.number_format($g['total_taxable'], 2));
        $this->line('  Total tax           : '.number_format($g['total_tax'], 2));
        $this->line('');
        $this->info('✓ Wrote '.$path.' ('.number_format($bytes).' bytes)');
        $this->line("  Upload it via the GST portal's Returns Offline Tool, or the portal's JSON import.");

        return self::SUCCESS;
    }
}
