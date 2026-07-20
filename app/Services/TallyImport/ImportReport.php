<?php

namespace App\Services\TallyImport;

use App\Services\BalanceService;

/**
 * The customer-facing summary of one import run (Phase 7A) — identical in shape for
 * a dry-run (preview) and a committed run, so a CA can review exactly what WILL
 * happen and then confirm the same numbers happened. Rendered as plain, unambiguous
 * text by the artisan commands.
 */
class ImportReport
{
    public ?string $company = null;
    public string $sourceFile = '';
    public bool $dryRun = false;
    public bool $committed = false;
    public ?string $fatalError = null;

    /** @var array<string,array{created:int,reused:int}> */
    public array $entityCounts = [];
    /** @var array<int,string> */
    public array $entityNotes = [];

    public int $rawVoucherCount = 0;
    public int $vouchersPosted = 0;
    /** @var array<string,int> */
    public array $vouchersByType = [];
    public bool $reordered = false;
    /** @var array<int,string> */
    public array $postOrder = [];

    /** @var array<int,array{voucher:string,date:string,reason:string}> */
    public array $rejections = [];
    /** @var array<int,array{voucher:string,reason:string}> */
    public array $skipped = [];
    /** @var array<string,int> concept => count */
    public array $notImported = [];

    // Trial Balance / Balance Sheet (integer paise, Dr-terms where signed).
    public ?int $tbDr = null;
    public ?int $tbCr = null;
    public ?bool $tbBalanced = null;
    public ?bool $bsBalanced = null;
    public ?int $bsClosingStock = null;
    public ?int $bsAssetTotal = null;
    public ?int $bsLiabTotal = null;
    public ?int $bsLiabDiff = null;
    public ?int $bsAssetDiff = null;
    public ?int $netProfit = null;

    public float $parseMs = 0.0;
    public float $importMs = 0.0;
    public float $totalMs = 0.0;

    public function hasRejections(): bool
    {
        return ! empty($this->rejections);
    }

    /** Human labels for the per-entity counts, in a stable display order. */
    private const ENTITY_LABELS = [
        'groups' => 'Account groups',
        'ledgers' => 'Ledgers',
        'stock_groups' => 'Stock groups',
        'units' => 'Units',
        'godowns' => 'Godowns',
        'cost_centres' => 'Cost centres',
        'stock_items' => 'Stock items',
    ];

    /** @return array<int,string> the full report as text lines */
    public function lines(): array
    {
        $L = [];
        $rule = str_repeat('─', 66);

        $mode = $this->dryRun ? 'DRY-RUN (preview — nothing written)' : ($this->committed ? 'COMMITTED' : 'ROLLED BACK');
        $L[] = $rule;
        $L[] = '  ZeroBook · Tally-Data Migration  —  '.$mode;
        $L[] = $rule;
        $L[] = '  Source   : '.$this->sourceFile;
        if ($this->company) {
            $L[] = '  Company  : '.$this->company;
        }
        $L[] = '';

        if ($this->fatalError) {
            $L[] = '  ✗ IMPORT ABORTED: '.$this->fatalError;
            $L[] = '  Nothing was written — the database is exactly as it was.';
            $L[] = $rule;

            return $L;
        }

        // ── Masters ──
        $L[] = '  MASTERS';
        foreach (self::ENTITY_LABELS as $key => $labelText) {
            $c = $this->entityCounts[$key] ?? ['created' => 0, 'reused' => 0];
            if ($c['created'] === 0 && $c['reused'] === 0) {
                continue;
            }
            $L[] = sprintf('    %-16s %4d created, %4d reused', $labelText, $c['created'], $c['reused']);
        }
        foreach ($this->entityNotes as $n) {
            $L[] = '    · '.$n;
        }
        $L[] = '';

        // ── Vouchers ──
        $L[] = '  VOUCHERS';
        $L[] = sprintf('    Parsed from file : %d', $this->rawVoucherCount);
        $L[] = sprintf('    Posted           : %d', $this->vouchersPosted);
        ksort($this->vouchersByType);
        foreach ($this->vouchersByType as $type => $n) {
            $L[] = sprintf('      %-16s %d', $type, $n);
        }
        $L[] = '    Chronology       : '.($this->reordered
            ? 'source was out of date order → re-sorted into strict chronological order'
            : 'source already in chronological order');
        $L[] = '';

        // ── Trial Balance / Balance Sheet ──
        if ($this->tbBalanced !== null) {
            $L[] = '  POST-IMPORT ACCOUNTS';
            $L[] = sprintf('    Trial Balance    : Dr %s = Cr %s   [%s]',
                BalanceService::money((int) $this->tbDr),
                BalanceService::money((int) $this->tbCr),
                $this->tbBalanced ? 'BALANCED' : 'OUT OF BALANCE');
            $L[] = sprintf('    Net Profit       : %s', BalanceService::money((int) $this->netProfit));
            $L[] = sprintf('    Stock-in-Hand    : %s', BalanceService::money((int) $this->bsClosingStock));
            $bsLine = sprintf('    Balance Sheet    : Assets %s = Liabilities %s   [%s]',
                BalanceService::money((int) $this->bsAssetTotal),
                BalanceService::money((int) $this->bsLiabTotal),
                $this->bsBalanced ? 'BALANCED' : 'OUT OF BALANCE');
            $L[] = $bsLine;
            $diff = (int) $this->bsLiabDiff + (int) $this->bsAssetDiff;
            if ($diff !== 0) {
                $side = ((int) $this->bsLiabDiff) !== 0 ? 'Liabilities' : 'Assets';
                $L[] = sprintf('    Difference in opening balances : %s (%s side) — mirrors Tally; = opening stock not backed by a capital opening',
                    BalanceService::money($diff), $side);
            }
            $L[] = '';
        }

        // ── Not imported ──
        $L[] = '  NOT IMPORTED (no ZeroBook equivalent — review these in Tally)';
        if (empty($this->notImported) && empty($this->skipped)) {
            $L[] = '    (none)';
        } else {
            foreach ($this->notImported as $concept => $n) {
                $L[] = sprintf('    · %-52s ×%d', $concept, $n);
            }
            foreach ($this->skipped as $s) {
                $L[] = sprintf('    · %-52s  %s', $s['reason'], $s['voucher']);
            }
        }
        $L[] = '';

        // ── Rejections ──
        if ($this->hasRejections()) {
            $L[] = '  ✗ REJECTED VOUCHERS  (import rolled back — resolve in Tally, re-export)';
            foreach ($this->rejections as $r) {
                $L[] = sprintf('    · %s', $r['voucher']);
                $L[] = sprintf('        %s', $r['reason']);
            }
            $L[] = '';
        }

        // ── Timing / outcome ──
        $L[] = sprintf('  Timing   : parse %0.0f ms · import %0.0f ms · total %0.0f ms', $this->parseMs, $this->importMs, $this->totalMs);
        $L[] = $rule;
        if ($this->dryRun) {
            $L[] = $this->hasRejections()
                ? '  DRY-RUN result: REJECTIONS present — fix them before a real import.'
                : '  DRY-RUN result: clean. Re-run without --dry-run to commit.';
        } elseif ($this->committed) {
            $L[] = '  ✓ COMMITTED — the book is now in ZeroBook.';
        } else {
            $L[] = '  ✗ ROLLED BACK — no changes were written.';
        }
        $L[] = $rule;

        return $L;
    }
}
