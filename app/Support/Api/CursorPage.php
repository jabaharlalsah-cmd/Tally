<?php

namespace App\Support\Api;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;

/**
 * Phase 16B — one cursor-pagination shape for every list endpoint.
 *
 * There is no pagination helper in the codebase to reuse, so this is it. It cursors on the
 * primary key `id` — the only stable, monotonic ordering for vouchers (voucher `number` is
 * per-type/per-FY and NOT globally unique, so it cannot be a cursor). The limit is clamped to
 * [1, MAX] so a client cannot ask for an unbounded page.
 *
 * Envelope:
 *   { "data": [...], "pagination": { "next_cursor": "...|null", "limit": N } }
 *
 * next_cursor is an opaque string the client passes back as ?cursor=… . null means the last page.
 */
class CursorPage
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /**
     * @param  EloquentBuilder|QueryBuilderContract  $query   ordered/orderable by id
     * @param  callable(mixed):array                 $transform  row → API shape
     */
    public static function build(Request $request, $query, callable $transform, string $column = 'id'): array
    {
        $limit = self::resolveLimit($request);

        // Deterministic ascending order on the cursor column; cursorPaginate needs the ordering
        // to match the cursor column or it silently mis-pages.
        //
        // A malformed ?cursor= (hand-crafted, truncated, or from a different query) makes Laravel's
        // Cursor throw an UnexpectedValueException. That is bad CLIENT input, so it must be a 400,
        // not an uncaught 500 that also spams the error log — catch it and raise a clean envelope.
        try {
            $paginator = $query->orderBy($column)->cursorPaginate(
                perPage: $limit,
                cursorName: 'cursor',
                cursor: $request->query('cursor'),
            );
        } catch (\UnexpectedValueException) {
            throw new \App\Services\Api\ApiVoucherException('bad_request', 400, ['cursor' => 'The cursor is malformed.']);
        }

        return [
            'data' => array_map($transform, $paginator->items()),
            'pagination' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'limit' => $limit,
            ],
        ];
    }

    /** ?limit= clamped to [1, MAX]; default when absent or non-numeric. */
    public static function resolveLimit(Request $request): int
    {
        $raw = $request->query('limit');

        if ($raw === null || ! is_numeric($raw)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $raw));
    }
}
