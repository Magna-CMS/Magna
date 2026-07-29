<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Magna\Content\Entry;
use Magna\Delivery\Exceptions\DeliveryException;

/**
 * Keyset cursor paginator over (sortColumn, id).
 *
 * The cursor must carry every value the ORDER BY depends on, or pagination
 * skips or duplicates rows. Two token shapes:
 *
 *   - Sort by id (the default): the token is the URL-safe base64 of the last
 *     row's ULID. Ordering is by id alone, so the ULID is the whole keyset.
 *   - Sort by any other column (published_at, created_at, updated_at, a schema
 *     field): the token is URL-safe base64 of {"s": <sort value>, "i": <ulid>}.
 *     Ordering is (sortColumn, id), so BOTH the sort value and the id tiebreaker
 *     are needed to resume deterministically — the id disambiguates rows that
 *     share a sort value (e.g. two entries published in the same second).
 *
 * URL-safe base64: +/ replaced with -_ and = padding stripped, so the token
 * survives a query string (where + means space).
 *
 * Assumes the sort column is non-null for the rows being paged, which holds for
 * the published content the delivery API serves (published_at is set on
 * publish; created_at/updated_at/id are always set). NULLs in a sort column are
 * not part of the keyset contract.
 */
final class CursorPaginator
{
    /**
     * @param  Builder<Entry>  $query
     *
     * @throws DeliveryException if the cursor is malformed
     */
    public function paginate(
        Builder $query,
        int $perPage,
        ?string $encodedCursor,
        string $sortColumn = 'id',
        bool $sortAsc = false,
    ): PaginatedResult {
        $direction = $sortAsc ? 'asc' : 'desc';

        if ($encodedCursor !== null) {
            $this->applyCursorConstraint($query, $encodedCursor, $sortColumn, $sortAsc);
        }

        if ($sortColumn !== 'id') {
            $query->orderBy($sortColumn, $direction)->orderBy('id', $direction);
        } else {
            $query->orderBy('id', $direction);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Entry> $rows */
        $rows = $query->limit($perPage + 1)->get();

        $hasMore = $rows->count() > $perPage;

        /** @var Collection<int, Entry> $entries */
        $entries = ($hasMore ? $rows->slice(0, $perPage) : $rows)->values();

        $nextCursor = null;
        if ($hasMore) {
            $last = $entries->last();
            if ($last instanceof Entry) {
                $nextCursor = $this->encodeCursor($last, $sortColumn);
            }
        }

        return new PaginatedResult(
            entries: $entries,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            perPage: $perPage,
        );
    }

    /**
     * Constrain the query to rows strictly after the cursor position, using a
     * compound keyset predicate when sorting by a non-id column.
     *
     * @param  Builder<Entry>  $query
     *
     * @throws DeliveryException
     */
    private function applyCursorConstraint(Builder $query, string $encodedCursor, string $sortColumn, bool $sortAsc): void
    {
        $cursorOp = $sortAsc ? '>' : '<';

        if ($sortColumn === 'id') {
            $query->where('id', $cursorOp, $this->decodeId($encodedCursor));

            return;
        }

        [$sortValue, $cursorId] = $this->decodeComposite($encodedCursor);

        // Keyset over (sortColumn, id): a row comes "after" the cursor when its
        // sort value is strictly past the cursor's, OR ties the cursor's sort
        // value and its id is past the cursor's id. The id tiebreaker is what
        // makes pages deterministic when sort values repeat.
        $query->where(function (Builder $outer) use ($sortColumn, $cursorOp, $sortValue, $cursorId): void {
            $outer->where($sortColumn, $cursorOp, $sortValue)
                ->orWhere(function (Builder $tie) use ($sortColumn, $cursorOp, $sortValue, $cursorId): void {
                    $tie->where($sortColumn, '=', $sortValue)
                        ->where('id', $cursorOp, $cursorId);
                });
        });
    }

    /**
     * Decode a bare-ULID cursor (id sort). Kept for the id sort path and
     * backward compatibility with tokens issued before compound cursors.
     *
     * @throws DeliveryException
     */
    private function decodeId(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), strict: true);
        if ($decoded === false || ! preg_match('/^[0-9A-Z]{26}$/i', $decoded)) {
            throw new DeliveryException('Invalid or malformed cursor.');
        }

        return strtolower($decoded);
    }

    /**
     * Decode a composite {"s","i"} cursor (non-id sort).
     *
     * @return array{0: scalar|null, 1: string}
     *
     * @throws DeliveryException
     */
    private function decodeComposite(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), strict: true);
        if ($json === false) {
            throw new DeliveryException('Invalid or malformed cursor.');
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! array_key_exists('s', $data) || ! isset($data['i']) || ! is_string($data['i'])) {
            throw new DeliveryException('Invalid or malformed cursor.');
        }

        if (! preg_match('/^[0-9A-Z]{26}$/i', $data['i'])) {
            throw new DeliveryException('Invalid or malformed cursor.');
        }

        $sortValue = $data['s'];
        if ($sortValue !== null && ! is_scalar($sortValue)) {
            throw new DeliveryException('Invalid or malformed cursor.');
        }

        return [$sortValue, strtolower($data['i'])];
    }

    /**
     * Encode the cursor for the last row of a page. Bare ULID for id sort;
     * composite {sort value, id} for any other sort column.
     *
     * @throws DeliveryException on serialization failure
     */
    private function encodeCursor(Entry $last, string $sortColumn): string
    {
        if ($sortColumn === 'id') {
            return $this->base64Url($last->id);
        }

        $json = json_encode(['s' => $this->cursorValue($last, $sortColumn), 'i' => $last->id]);
        if ($json === false) {
            throw new DeliveryException('Failed to encode pagination cursor.');
        }

        return $this->base64Url($json);
    }

    /**
     * The exact stored value of the sort column, so the resumed keyset compares
     * against the same representation the database ordered by. getRawOriginal
     * bypasses casts (a datetime comes back as its DB string, not a Carbon),
     * keeping the value both JSON-serialisable and directly comparable in SQL.
     *
     * @return scalar|null
     */
    private function cursorValue(Entry $last, string $sortColumn): string|int|float|bool|null
    {
        $raw = $last->getRawOriginal($sortColumn);

        if ($raw === null || is_scalar($raw)) {
            return $raw;
        }

        // Non-scalar sort column value (not expected for a sortable column) —
        // fall back to a JSON string so the cursor stays serialisable and
        // deterministically comparable.
        $encoded = json_encode($raw);

        return $encoded === false ? '' : $encoded;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
