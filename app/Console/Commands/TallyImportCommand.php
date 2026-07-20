<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveCompany;
use App\Console\Concerns\RunsInTenantContext;
use App\Services\TallyImport\TallyImporter;
use Illuminate\Console\Command;

/**
 * Phase 7A — the Tally-Data Migration command. Phase 7B — tenant-scoped.
 *
 * Reads a Tally XML export (masters + vouchers) and imports it into ZeroBook inside
 * one all-or-nothing transaction. Server-side only: no keyboard/UI interaction, just
 * a progress log and a full summary.
 *
 * Under multi-tenancy it writes into ONE tenant's database and REFUSES to run against
 * the central database — pass --tenant=<subdomain>:
 *
 *   php artisan zerobook:tally-import company.xml --tenant=acme --dry-run   (preview)
 *   php artisan zerobook:tally-import company.xml --tenant=acme             (commit)
 *   …add -v / --verbose for the chronological post order.
 *
 * --verbose is Laravel's standard global flag (read via the output verbosity), so it
 * is not redeclared here.
 */
class TallyImportCommand extends Command
{
    use ResolvesActiveCompany;
    use RunsInTenantContext;

    protected $signature = 'zerobook:tally-import
        {file : path to the Tally XML export}
        {--dry-run : parse, validate and report without writing anything}
        {--company= : REQUIRED — the company (slug or id) to import into}
        {--tenant= : the tenant subdomain whose database to import into}';

    protected $description = 'Import a Tally XML export (masters + vouchers) into a tenant — dry-run and real modes, all-or-nothing';

    public function handle(TallyImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        // A Tally import writes accounting data — it MUST be scoped to a tenant.
        $scope = $this->resolveTenantScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        // Phase 12A — a Tally import writes/reads ONE company's books. Refuse to guess:
        // the tenant may hold many companies, and an import scoped to the wrong
        // one is silent data corruption. --company= is mandatory.
        if (trim((string) $this->option('company')) === '') {
            $this->error('Refusing to run without --company= — this tenant may hold multiple companies.');
            $this->line('Pass --company=<slug or id> to name the company whose books this command touches.');

            return self::FAILURE;
        }


        $dryRun = (bool) $this->option('dry-run');
        $this->line('');
        $this->info($dryRun
            ? 'Running a DRY-RUN — parsing, resolving and validating; the database will NOT be touched.'
            : 'Importing — this runs inside one transaction and commits only if every voucher validates.');

        $report = $scope(function () use ($importer, $file, $dryRun) {
            // Phase 12A — resolve the target company INSIDE the tenant scope; the
            // importer's masters and vouchers are stamped with it by the model layer.
            if (! $this->resolveActiveCompany()) {
                throw new \RuntimeException('Company resolution failed.');
            }

            return $importer->run($file, $dryRun);
        });

        $this->line('');
        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        if ($this->getOutput()->isVerbose() && ! empty($report->postOrder)) {
            $this->line('');
            $this->line('  Chronological post order:');
            foreach ($report->postOrder as $i => $label) {
                $this->line(sprintf('    %3d. %s', $i + 1, $label));
            }
        }

        return ($report->fatalError || $report->hasRejections()) ? self::FAILURE : self::SUCCESS;
    }
}
