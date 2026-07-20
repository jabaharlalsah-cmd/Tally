<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\TenantLifecycleEvent;
use App\Models\TenantUser;
use App\Notifications\ExportReady;
use App\Services\BalanceService;
use App\Services\Backups\BackupService;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Phase 14C — build a customer's complete data-export archive.
 *
 * Per company: the chart of accounts, all vouchers (one CSV per type) + entries, stock
 * masters + entries, allocations / TDS / inter-company lots, the F11 profile as JSON, and
 * authoritative Trial Balance / Balance Sheet / P&L snapshots. Plus a full mysqldump so a
 * technical person can restore the raw books, and a README. Everything zipped to private
 * storage; a signed download link is emailed.
 *
 * COMPLETENESS is the invariant: every row of every company-scoped table lands in the CSVs
 * (headers always written, so an importer sees the columns). A silent omission is the worst
 * kind of data loss.
 */
class ExportTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Company-scoped tables exported verbatim (one CSV each). */
    private const COMPANY_TABLES = [
        'account_groups', 'ledgers', 'cost_centres', 'currencies', 'exchange_rates',
        'stock_groups', 'units', 'godowns', 'stock_items', 'stock_entries',
        'bill_allocations', 'cost_allocations', 'tds_deductions', 'tds_deductee_ytd',
        'tds_challans', 'stock_lots', 'order_lines', 'order_fulfillments',
        'voucher_intercompany_tags',
    ];

    public function __construct(public int $exportId)
    {
    }

    public function handle(BackupService $backups, BalanceService $balances): void
    {
        $export = TenantExport::find($this->exportId);
        if (! $export) {
            return;
        }
        $tenant = Tenant::find($export->tenant_id);
        if (! $tenant) {
            return;
        }

        $export->update(['status' => 'processing', 'started_at' => now()]);

        try {
            $path = $this->build($tenant, $backups);

            $export->update([
                'status' => 'completed',
                'file_path' => $path,
                'file_size_bytes' => Storage::disk('local')->size($path),
                'completed_at' => now(),
                'download_expires_at' => now()->addDays((int) config('zerobook.export_link_days', 7)),
            ]);

            TenantLifecycleEvent::create([
                'tenant_id' => $tenant->id,
                'event' => 'export_generated',
                'triggered_by_user_id' => $export->initiated_by_user_id,
                'triggered_by_admin_id' => $export->initiated_by_admin_id,
                'notes' => "export #{$export->id} ({$export->fresh()->humanSize()})",
            ]);

            if ($owner = $this->owner($tenant->id)) {
                $owner->notify(new ExportReady($export->fresh()));
            }
        } catch (Throwable $e) {
            $export->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
        }
    }

    private function build(Tenant $tenant, BackupService $backups): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zbexp').'.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create export archive.');
        }

        $zip->addFromString('README.txt', $this->readme($tenant));

        // Full mysqldump (gzipped) for a raw restore elsewhere.
        $zip->addFromString('database.sql.gz', gzencode($backups->dumpDatabase($tenant->database()->getName()), 6));

        // Per-company human-readable CSVs + reports.
        $balances = app(BalanceService::class);
        $tenant->run(function () use ($zip, $balances) {
            foreach (Company::orderBy('id')->get() as $company) {
                $dir = 'companies/'.Str::slug($company->slug ?: $company->name ?: ('company-'.$company->id)).'/';

                ActiveCompany::runAs($company->id, function () use ($zip, $dir, $company, $balances) {
                    // Chart of accounts + every company-scoped table.
                    $zip->addFromString($dir.'account_groups.csv', $this->csvForTable('account_groups', $company->id));
                    $zip->addFromString($dir.'ledgers.csv', $this->csvForTable('ledgers', $company->id));

                    // Vouchers — one CSV per type present, plus all voucher entries.
                    foreach (DB::table('vouchers')->where('company_id', $company->id)->distinct()->pluck('type') as $type) {
                        $zip->addFromString($dir.'vouchers_'.$type.'.csv', $this->csvFrom('vouchers', DB::table('vouchers')->where('company_id', $company->id)->where('type', $type)));
                    }
                    $zip->addFromString($dir.'voucher_entries.csv', $this->csvForTable('voucher_entries', $company->id));

                    foreach (self::COMPANY_TABLES as $table) {
                        if ($table === 'account_groups' || $table === 'ledgers') {
                            continue; // already written above
                        }
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                            $zip->addFromString($dir.$table.'.csv', $this->csvForTable($table, $company->id));
                        }
                    }

                    // F11 profile.
                    $features = DB::table('company_features')->where('company_id', $company->id)->first();
                    $zip->addFromString($dir.'company_features.json', json_encode($features, JSON_PRETTY_PRINT));

                    // Authoritative report snapshots at export time (all-time period).
                    $from = Carbon::parse('1900-01-01');
                    $to = Carbon::parse('2100-01-01');
                    $zip->addFromString($dir.'reports/Trial Balance.csv', $this->reportCsv($balances->trialBalance($from, $to)));
                    $zip->addFromString($dir.'reports/Balance Sheet.csv', $this->reportCsv($balances->balanceSheet($from, $to)));
                    $zip->addFromString($dir.'reports/Profit and Loss.csv', $this->reportCsv($balances->profitAndLoss($from, $to)));
                });
            }
        });

        $zip->close();

        $path = "exports/{$tenant->id}/".now()->format('Y-m-d-His').'-export.zip';
        Storage::disk('local')->put($path, file_get_contents($tmp));
        @unlink($tmp);

        return $path;
    }

    // ── CSV helpers ───────────────────────────────────────────────────────────

    private function csvForTable(string $table, int $companyId): string
    {
        return $this->csvFrom($table, DB::table($table)->where('company_id', $companyId));
    }

    /** Header (always, from the table columns) + every row. */
    private function csvFrom(string $table, $query): string
    {
        $columns = Schema::getColumnListing($table);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns);

        $query->orderBy('id')->chunk(2000, function ($rows) use ($out, $columns) {
            foreach ($rows as $row) {
                $arr = (array) $row;
                fputcsv($out, array_map(fn ($col) => $arr[$col] ?? '', $columns));
            }
        });

        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return $content;
    }

    /** Flatten any BalanceService report tree to a readable CSV. */
    private function reportCsv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['section', 'level', 'kind', 'name', 'nature', 'opening', 'net', 'closing']);

        $walk = function (array $nodes, string $section, int $level) use (&$walk, $out) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                fputcsv($out, [
                    $section, $level, $node['type'] ?? 'row', $node['name'] ?? '', $node['nature'] ?? '',
                    isset($node['opening']) ? $node['opening'] / 100 : '',
                    isset($node['net']) ? $node['net'] / 100 : '',
                    isset($node['closing']) ? $node['closing'] / 100 : '',
                ]);
                if (! empty($node['children'])) {
                    $walk($node['children'], $section, $level + 1);
                }
                if (! empty($node['ledgers'])) {
                    $walk($node['ledgers'], $section, $level + 1);
                }
            }
        };

        $wrote = false;
        foreach ($report as $key => $val) {
            if (is_array($val) && isset($val[0]) && is_array($val[0]) && (isset($val[0]['name']) || isset($val[0]['type']))) {
                $walk($val, (string) $key, 0);
                $wrote = true;
            } elseif (! is_array($val)) {
                fputcsv($out, ['_totals', '', $key, (string) $val, '', '', '', '']);
            }
        }
        if (! $wrote && isset($report['roots'])) {
            $walk($report['roots'], 'roots', 0);
        }

        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return $content;
    }

    private function readme(Tenant $tenant): string
    {
        $when = now()->format('d-M-Y H:i');

        return <<<TXT
        ZeroBook — data export for "{$tenant->name}" ({$tenant->id})
        Generated: {$when}

        This archive is a complete, human-readable copy of your books.

        STRUCTURE
        ---------
        README.txt                     — this file.
        database.sql.gz                — a full MySQL dump of your tenant database. A technical
                                         person can restore the raw books with:
                                             gunzip -c database.sql.gz | mysql your_new_db
        companies/<company>/           — one folder per company in your account:
          account_groups.csv           — the account-group tree.
          ledgers.csv                  — every ledger (opening balances, tax settings, …).
          vouchers_<type>.csv          — all vouchers of each type (payment, sales, journal, …).
          voucher_entries.csv          — every debit/credit line; join to vouchers on voucher_id.
                                         Amounts are in PAISE (integer). dr_cr = Dr | Cr.
          stock_*.csv, units.csv, godowns.csv, stock_entries.csv — inventory masters & movements.
          bill_allocations.csv, cost_allocations.csv, tds_deductions.csv,
          stock_lots.csv               — analytical / statutory detail linked to vouchers.
          company_features.json        — your F11 feature profile.
          reports/                     — authoritative snapshots at the moment of export:
            Trial Balance.csv, Balance Sheet.csv, Profit and Loss.csv
                                         (amounts shown in your base currency units).

        Every table's CSV includes a header row of column names even when empty, so nothing is
        ambiguous. Amounts in the raw CSVs are stored the way ZeroBook stores them (paise for
        money, 4-dp for quantities); the reports show them in currency units.
        TXT;
    }

    private function owner(string $tenantId): ?TenantUser
    {
        return TenantUser::where('tenant_id', $tenantId)
            ->orderByRaw("role = 'owner' desc")->orderBy('id')->first();
    }
}
