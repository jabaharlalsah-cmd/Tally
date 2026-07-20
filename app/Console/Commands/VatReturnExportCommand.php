<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Console\Concerns\RunsInTenantContext;
use App\Services\Vat\NepalVatReturnService;
use App\Support\NepalDate;
use App\Support\VatReturnExport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 9B — write a tenant's Nepal VAT return (अनुसूची-१०) for a BS period.
 *
 *   php artisan zerobook:vat-return-export --tenant=acme --period=2082-04 --output=vat.json
 *
 * The IRD taxpayer portal accepts NO return file — the VAT return is a web form. So the
 * JSON written here is a **transcription document**: every box of Schedule 10, in the
 * form's own order and with the form's own Devanagari label, so the figures can be keyed
 * into the portal without re-computing anything. The console prints the same boxes.
 *
 * Refuses on the CENTRAL database, on a GST-regime tenant, and without a company PAN.
 */
class VatReturnExportCommand extends Command
{
    use ResolvesActiveCompany;
    use RunsInTenantContext;

    protected $signature = 'zerobook:vat-return-export
        {--tenant= : the tenant subdomain whose books to export}
        {--company= : REQUIRED — the company (slug or id) whose books to export}
        {--period= : the BS return period as YYYY-MM, e.g. 2082-04 for Shrawan 2082 (default: last completed BS month)}
        {--carry-forward=0 : box 6 — credit left unadjusted from the previous return, in whole rupees}
        {--output= : where to write the JSON (default: vat-return-<pan>-<period>.json in the cwd)}';

    protected $description = "Export a tenant's Nepal VAT return (Schedule 10 / अनुसूची-१०) as a transcription document";

    public function handle(): int
    {
        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        // Phase 12A — a VAT-return export writes/reads ONE company's books. Refuse to guess:
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
        $period = (string) ($this->option('period') ?: NepalDate::lastCompletedPeriod());
        $carryForward = (int) $this->option('carry-forward');

        try {
            NepalDate::assertPeriod($period);
            $svc = app(NepalVatReturnService::class);
            $return = $svc->return($period, $carryForward);
            $preview = $svc->preview($period, $carryForward);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: VatReturnExport::filename($return['header']['pan'], $period));
        $bytes = VatReturnExport::write($path, $return);

        $b = $return['boxes'];
        $rs = fn (int $n) => number_format($n);

        $this->info('मूल्य अभिवृद्धि कर विवरण फाराम (अनुसूची-१०) · VAT Return');
        $this->line('  PAN '.$return['header']['pan'].' · '.$preview['period_label'].' · FY '.$preview['fiscal_year']);
        $this->line('  Gregorian '.$preview['gregorian_from'].' → '.$preview['gregorian_to']);
        $this->line('');
        $this->table(['Box', 'Label (Nepali)', 'कारोवार मूल्य', 'क्रेडिट', 'डेविट'], [
            ['1.1', $b['1.1']['np'], $rs($b['1.1']['value']), '—', $rs($b['1.1']['debit'])],
            ['1.2', $b['1.2']['np'], $rs($b['1.2']['value']), '—', '—'],
            ['1.3', $b['1.3']['np'], $rs($b['1.3']['value']), '—', '—'],
            ['2.1', $b['2.1']['np'], $rs($b['2.1']['value']), $rs($b['2.1']['credit']), '—'],
            ['2.2', $b['2.2']['np'], $rs($b['2.2']['value']), $rs($b['2.2']['credit']), '—'],
            ['2.3', $b['2.3']['np'], $rs($b['2.3']['value']), '—', '—'],
            ['2.4', $b['2.4']['np'], $rs($b['2.4']['value']), '—', '—'],
            ['3.1', $b['3.1']['np'], '—', $rs($b['3.1']['credit']), $rs($b['3.1']['debit'])],
            ['4', $b['4']['np'], '—', $rs($b['4']['credit']), $rs($b['4']['debit'])],
        ]);
        $this->line('  5.  '.$b['5']['np'].'  = '.$b['5']['sign'].' '.$rs(abs($b['5']['amount'])));
        $this->line('  6.  '.$b['6']['np'].'  = '.$rs($b['6']['amount']));
        $this->line('  7.  '.$b['7']['np'].'  = '.$b['7']['sign'].' '.$rs(abs($b['7']['amount'])).($b['7']['amount'] >= 0 ? '   (payable)' : '   (credit carried forward)'));
        $this->line('');
        $this->line('  11. '.$b['11']['np'].':');
        foreach ($b['11']['rows'] as $row) {
            $this->line('        '.str_pad($row['en'], 30).' '.$row['np'].'  = '.$row['count']);
        }
        $this->line('');
        $this->info('✓ Wrote '.$path.' ('.number_format($bytes).' bytes)');
        $this->line('  The IRD portal accepts no return file — sign in at taxpayerportal.ird.gov.np and');
        $this->line('  transcribe these boxes into the E-VAT Return Entry form, then record the submission ref.');

        return self::SUCCESS;
    }
}
