<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Console\Concerns\RunsInTenantContext;
use App\Services\Gst\GstnFormat;
use App\Services\Gst\GstReturnService;
use App\Support\GstReturnExport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 9A — write a tenant's GSTR-3B summary return for a period as a portal-ready JSON.
 *
 *   php artisan zerobook:gstr3b-export --tenant=acme --period=042026 --output=gstr3b.json
 *
 * The generated file carries NO tax-payment section: the official GSTR-3B Excel Utility
 * emits none (payment/offset happens on the portal against the electronic ledgers). The
 * net payable is printed here so it can be eyeballed before filing.
 */
class Gstr3bExportCommand extends Command
{
    use ResolvesActiveCompany;
    use RunsInTenantContext;

    protected $signature = 'zerobook:gstr3b-export
        {--tenant= : the tenant subdomain whose books to export}
        {--company= : REQUIRED — the company (slug or id) whose books to export}
        {--period= : the return period as MMYYYY, e.g. 042026 (default: last completed month)}
        {--output= : where to write the JSON (default: gstr3b-<gstin>-<period>.json in the cwd)}';

    protected $description = "Export a tenant's GSTR-3B (summary return) as a GSTN-schema JSON file";

    public function handle(): int
    {
        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        // Phase 12A — a GSTR-3B export writes/reads ONE company's books. Refuse to guess:
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
            $json = $svc->gstr3b($period);
            $preview = $svc->preview($period);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: GstReturnExport::filename('gstr3b', $json['gstin'], $period));
        $bytes = GstReturnExport::write($path, $json);

        $g = $preview['gstr3b'];
        $this->info('GSTR-3B · '.GstnFormat::periodLabel($period).' · GSTIN '.$json['gstin']);
        $this->line('');
        $this->table(['Figure', 'Amount'], [
            ['3.1(a) Outward taxable value', number_format($g['outward_taxable'], 2)],
            ['  Output IGST', number_format($g['output_igst'], 2)],
            ['  Output CGST', number_format($g['output_cgst'], 2)],
            ['  Output SGST', number_format($g['output_sgst'], 2)],
            ['4(C) Net ITC available', number_format($g['itc_total'], 2)],
            ['  ITC IGST', number_format($g['itc_igst'], 2)],
            ['  ITC CGST', number_format($g['itc_cgst'], 2)],
            ['  ITC SGST', number_format($g['itc_sgst'], 2)],
            ['Net payable (output − ITC)', number_format($g['net_payable'], 2)],
            ['3.2 Inter-state unregistered rows', $g['inter_state_unreg_rows']],
        ]);
        $this->line('');
        $this->info('✓ Wrote '.$path.' ('.number_format($bytes).' bytes)');
        $this->line('  Note: GSTR-3B JSON carries no tax-payment section — offset the liability on the portal.');

        return self::SUCCESS;
    }
}
