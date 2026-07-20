<?php

namespace App\Services\TallyImport;

use App\Models\CompanyFeature;
use App\Models\Voucher;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a Tally-data migration end to end (Phase 7A).
 *
 * The whole import — every master and every voucher — runs inside ONE database
 * transaction. The customer gets a complete, verified book or the database they
 * started with; never anything in between:
 *
 *   • --dry-run           → parse, resolve, post and REPORT, then always roll back.
 *   • any rejection       → roll back (a rejected voucher means the export must be
 *                           fixed and re-run; a partial import is never left).
 *   • clean, non-dry-run  → commit.
 *
 * The post-import Trial Balance / Balance Sheet are computed INSIDE the transaction
 * (against the uncommitted rows) so the summary reflects exactly what would be — or
 * was — committed.
 */
class TallyImporter
{
    public function run(string $path, bool $dryRun): ImportReport
    {
        $report = new ImportReport();
        $report->sourceFile = $path;
        $report->dryRun = $dryRun;

        $t0 = microtime(true);
        $parsed = (new TallyXmlParser())->parse($path);
        $report->parseMs = (microtime(true) - $t0) * 1000;

        $report->company = $parsed['company'];
        $report->notImported = $parsed['not_imported'];
        $report->rawVoucherCount = count($parsed['vouchers']);

        $tImport = microtime(true);
        DB::beginTransaction();
        try {
            BulkMode::run(function () use ($parsed, $report) {
                $this->configureFeatures($parsed);

                $resolver = new Resolver();
                $resolver->resolve($parsed['masters']);
                $report->entityCounts = $resolver->counts;
                $report->entityNotes = $resolver->notes;

                $importer = new VoucherImporter();
                $importer->import($parsed['vouchers'], $resolver);
                $report->vouchersPosted = $importer->posted;
                $report->vouchersByType = $importer->byType;
                $report->rejections = $importer->rejections;
                $report->reordered = $importer->reordered;
                $report->postOrder = $importer->postOrder;
                foreach ($importer->skipped as $s) {
                    $report->skipped[] = $s;
                }

                $this->computeAccounts($parsed['vouchers'], $report);
            });
        } catch (Throwable $e) {
            DB::rollBack();
            $report->fatalError = $e->getMessage();
            $report->committed = false;
            $report->importMs = (microtime(true) - $tImport) * 1000;
            $report->totalMs = (microtime(true) - $t0) * 1000;

            return $report;
        }

        $report->committed = ! $dryRun && ! $report->hasRejections();
        if ($report->committed) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        $report->importMs = (microtime(true) - $tImport) * 1000;
        $report->totalMs = (microtime(true) - $t0) * 1000;

        return $report;
    }

    /**
     * Switch on the company F11 features the imported data requires, so the shared
     * posting path enforces (and the reports read) them consistently. Only enabled,
     * never disabled — GST/VAT are left to the operator's company configuration so
     * an import never silently changes a tax regime.
     */
    private function configureFeatures(array $parsed): void
    {
        $needBill = false;
        $needCost = false;
        foreach ($parsed['masters']['ledgers'] as $l) {
            $needBill = $needBill || ! empty($l['bill_by_bill']);
            $needCost = $needCost || ! empty($l['cost_centres']);
        }
        foreach ($parsed['vouchers'] as $v) {
            foreach ($v['lines'] as $ln) {
                if (! empty($ln['cost_allocations'])) {
                    $needCost = true;
                }
                if (! empty($ln['bill_allocations'])) {
                    $needBill = true;
                }
            }
        }

        $company = CompanyFeature::current();
        $updates = [];
        if ($needBill && ! $company->bill_by_bill) {
            $updates['bill_by_bill'] = true;
        }
        if ($needCost && ! $company->cost_centres) {
            $updates['cost_centres'] = true;
        }
        if ($updates) {
            $company->update($updates);
        }
    }

    /** Compute the post-import Trial Balance + Balance Sheet over the imported span. */
    private function computeAccounts(array $vouchers, ImportReport $report): void
    {
        if (empty($vouchers)) {
            return;
        }
        $dates = array_map(fn ($v) => $v['date'], $vouchers);
        $from = Carbon::parse(min($dates))->startOfDay();
        // Extend `to` to the financial-year end of the latest voucher so the closing
        // stock / balances cover the whole imported book.
        $latest = Carbon::parse(max($dates));
        $to = Voucher::fyOpenFor(Voucher::fyStartFor($latest))->addYear()->subDay();

        $bs = app(BalanceService::class);
        $tb = $bs->trialBalance($from, $to);
        $sheet = $bs->balanceSheet($from, $to);
        $pl = $bs->profitAndLoss($from, $to);

        $report->tbDr = $tb['total_dr'];
        $report->tbCr = $tb['total_cr'];
        $report->tbBalanced = $tb['balanced'];
        $report->bsBalanced = $sheet['balanced'];
        $report->bsClosingStock = $sheet['closing_stock'];
        $report->bsAssetTotal = $sheet['asset_total'];
        $report->bsLiabTotal = $sheet['liability_total'];
        $report->bsLiabDiff = $sheet['liability_diff'];
        $report->bsAssetDiff = $sheet['asset_diff'];
        $report->netProfit = $pl['net'];
    }
}
