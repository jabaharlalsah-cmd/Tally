<?php

namespace App\Livewire\Concerns;

use App\Services\BalanceService;

/**
 * Flattens a BalanceService group tree into flat display rows for the report
 * views + the client-side reportScreen controller. Each row carries its
 * ancestor keys and depth so the controller can expand/collapse and navigate
 * entirely client-side (no server round-trip on arrow keys).
 */
trait BuildsReportRows
{
    /** Trial-Balance rows: Dr/Cr split by the sign of the closing. */
    protected function flattenTb(array $nodes, array $ancestors = [], int $depth = 0): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            $key = 'g'.$node['id'];
            $collapsible = ! empty($node['children']) || ! empty($node['ledgers']);
            $rows[] = [
                'key' => $key,
                'kind' => 'group',
                'depth' => $depth,
                'label' => $node['name'],
                'dr' => $node['closing'] > 0 ? BalanceService::money($node['closing']) : '',
                'cr' => $node['closing'] < 0 ? BalanceService::money($node['closing']) : '',
                'open_dr' => $node['opening'] > 0 ? BalanceService::money($node['opening']) : '',
                'open_cr' => $node['opening'] < 0 ? BalanceService::money($node['opening']) : '',
                'closing_paise' => $node['closing'],
                'amount' => '',
                'ledger_id' => null,
                'group_id' => $node['id'],
                'ancestors' => $ancestors,
                'collapsible' => $collapsible,
                'col' => 'full',
            ];
            $child = array_merge($ancestors, [$key]);
            $rows = array_merge($rows, $this->flattenTb($node['children'], $child, $depth + 1));
            foreach ($node['ledgers'] as $L) {
                $rows[] = [
                    'key' => 'l'.$L['id'],
                    'kind' => 'ledger',
                    'depth' => $depth + 1,
                    'label' => $L['name'],
                    'dr' => $L['closing'] > 0 ? BalanceService::money($L['closing']) : '',
                    'cr' => $L['closing'] < 0 ? BalanceService::money($L['closing']) : '',
                    'open_dr' => $L['opening'] > 0 ? BalanceService::money($L['opening']) : '',
                    'open_cr' => $L['opening'] < 0 ? BalanceService::money($L['opening']) : '',
                    'closing_paise' => $L['closing'],
                    'amount' => '',
                    'ledger_id' => $L['id'],
                    'group_id' => null,
                    'ancestors' => $child,
                    'collapsible' => false,
                    'col' => 'full',
                ];
            }
        }

        return $rows;
    }

    /** Two-column rows (BS/P&L): a single magnitude column, side implied by col. */
    protected function flattenMag(array $nodes, string $col, array $ancestors = [], int $depth = 0): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            $key = 'g'.$node['id'];
            $collapsible = ! empty($node['children']) || ! empty($node['ledgers']);
            $rows[] = [
                'key' => $key,
                'kind' => 'group',
                'depth' => $depth,
                'label' => $node['name'],
                'dr' => '',
                'cr' => '',
                'amount' => BalanceService::money($node['closing']),
                'ledger_id' => null,
                'group_id' => $node['id'],
                'ancestors' => $ancestors,
                'collapsible' => $collapsible,
                'col' => $col,
            ];
            $child = array_merge($ancestors, [$key]);
            $rows = array_merge($rows, $this->flattenMag($node['children'], $col, $child, $depth + 1));
            foreach ($node['ledgers'] as $L) {
                $rows[] = [
                    'key' => 'l'.$L['id'],
                    'kind' => 'ledger',
                    'depth' => $depth + 1,
                    'label' => $L['name'],
                    'dr' => '',
                    'cr' => '',
                    'amount' => BalanceService::money($L['closing']),
                    'ledger_id' => $L['id'],
                    'group_id' => null,
                    'ancestors' => $child,
                    'collapsible' => false,
                    'col' => $col,
                ];
            }
        }

        return $rows;
    }

    /** A non-navigable special row (Nett Profit, Difference, Total). */
    protected function specialRow(string $key, string $label, string $amount, string $col, string $kind = 'special'): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'depth' => 0,
            'label' => $label,
            'dr' => '',
            'cr' => '',
            'amount' => $amount,
            'ledger_id' => null,
            'group_id' => null,
            'ancestors' => [],
            'collapsible' => false,
            'col' => $col,
        ];
    }
}
