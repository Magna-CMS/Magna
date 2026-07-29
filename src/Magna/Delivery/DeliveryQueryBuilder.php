<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\Field;
use Magna\Delivery\Exceptions\DeliveryException;
use Magna\Settings\GeneralSettings;
use Magna\Settings\LocalizationSettings;

/**
 * Builds a base Entry query from request parameters.
 *
 * Handles status filtering, and applies the ?filter[field][op]=value parameter
 * with a safe operator allowlist to prevent SQL injection.
 */
final class DeliveryQueryBuilder
{
    /** @var list<string> */
    private const SAFE_OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like', 'in', 'nin'];

    /**
     * Comparison operators handled by the generic where() branch. `like` (a
     * literal contains-match), `in` and `nin` are handled separately.
     *
     * @var array<string, string>
     */
    private const OP_MAP = [
        'eq' => '=',
        'neq' => '!=',
        'lt' => '<',
        'lte' => '<=',
        'gt' => '>',
        'gte' => '>=',
    ];

    /** Upper bound on a `like` filter value, to cap the work one filter can request. */
    private const MAX_LIKE_LENGTH = 256;

    /**
     * Build a base query for the given content type, pre-filtered by status and locale.
     *
     * When the content type is localizable, ?locale= selects the target locale.
     * If no entry exists for that locale the fallback chain from LocalizationSettings
     * is tried; the winning locale is returned via the $resolvedLocale out-param.
     *
     * @return Builder<Entry>
     *
     * @throws DeliveryException on invalid filter column, operator, or injection attempt
     */
    public function build(
        ContentType $type,
        Request $request,
        ?string &$resolvedLocale = null,
    ): Builder {
        $query = Entry::type($type->handle);
        $query->where('status', EntryStatus::Published->value);

        if ($type->localizable) {
            $this->applyLocale($query, $request, $resolvedLocale);
        }

        $filterRaw = $request->input('filter');
        if (is_array($filterRaw)) {
            $this->applyFilters($query, $filterRaw, $type);
        }

        return $query;
    }

    /**
     * Same as build() but includes draft entries when a valid preview context is
     * signalled by the caller.
     *
     * @return Builder<Entry>
     *
     * @throws DeliveryException
     */
    public function buildWithPreview(ContentType $type, Request $request, ?string &$resolvedLocale = null): Builder
    {
        $query = Entry::type($type->handle);
        $query->whereIn('status', [EntryStatus::Published->value, EntryStatus::Draft->value]);

        if ($type->localizable) {
            $this->applyLocale($query, $request, $resolvedLocale);
        }

        $filterRaw = $request->input('filter');
        if (is_array($filterRaw)) {
            $this->applyFilters($query, $filterRaw, $type);
        }

        return $query;
    }

    /**
     * Resolve the best locale for this request and constrain the query.
     *
     * Priority: ?locale= param → LocalizationSettings::fallback_locale → GeneralSettings::default_locale.
     * The resolved locale is written back via the out-param so controllers can
     * include it in surrogate keys and response headers.
     *
     * @param  Builder<Entry>  $query
     *
     * @param-out string $resolvedLocale
     */
    private function applyLocale(Builder $query, Request $request, ?string &$resolvedLocale): void
    {
        $locSettings = LocalizationSettings::get();
        $generalSettings = GeneralSettings::get();
        $default = $generalSettings->default_locale;

        $requested = $request->string('locale')->value();

        // Build the resolution chain: requested → fallback → default → '' (deduped).
        // Do NOT use array_filter here: the '' sentinel is intentional — it lets
        // pre-locale content (locale='') be reached as a last resort. array_filter
        // with no callback would strip '' because it is falsy.
        $chain = array_values(array_unique([$requested, $locSettings->fallback_locale, $default, '']));

        // Pick the first locale in the chain that has at least one matching entry.
        foreach ($chain as $locale) {
            if ($query->clone()->where('locale', $locale)->exists()) {
                $query->where('locale', $locale);
                $resolvedLocale = $locale;

                return;
            }
        }

        // Nothing found in the chain — constrain to the default locale anyway
        // so the query returns an empty set rather than cross-locale results.
        $query->where('locale', $default);
        $resolvedLocale = $default;
    }

    /**
     * @param  Builder<Entry>  $query
     * @param  array<mixed, mixed>  $filters
     *
     * @throws DeliveryException
     */
    private function applyFilters(Builder $query, array $filters, ContentType $type): void
    {
        $validColumns = $this->validFilterColumns($type);

        foreach ($filters as $column => $conditions) {
            if (! is_string($column)) {
                throw new DeliveryException('Filter column key must be a string.');
            }

            if (! in_array($column, $validColumns, true)) {
                throw new DeliveryException("Unknown or disallowed filter column: '{$column}'.");
            }

            if (! is_array($conditions)) {
                // Simple equality shorthand: ?filter[field]=value
                $query->where($column, '=', $conditions);

                continue;
            }

            foreach ($conditions as $op => $value) {
                if (! is_string($op)) {
                    throw new DeliveryException('Filter operator must be a string.');
                }

                if (! in_array($op, self::SAFE_OPERATORS, true)) {
                    throw new DeliveryException(
                        "Filter operator '{$op}' is not allowed. Permitted: ".implode(', ', self::SAFE_OPERATORS).'.'
                    );
                }

                if ($op === 'in') {
                    $query->whereIn($column, is_array($value) ? $value : [$value]);
                } elseif ($op === 'nin') {
                    $query->whereNotIn($column, is_array($value) ? $value : [$value]);
                } elseif ($op === 'like') {
                    $this->applyLike($query, $column, $value);
                } else {
                    // PHPStan proves $op is a key of OP_MAP at this point (in/nin/like
                    // handled above, and SAFE_OPERATORS ⊇ OP_MAP keys ∪ {in,nin,like} —
                    // all remaining ops are in the map).
                    $query->where($column, self::OP_MAP[$op], $value);
                }
            }
        }
    }

    /**
     * Apply a `like` filter as a safe literal-substring (contains) match.
     *
     * LIKE metacharacters (`%`, `_`) and the backslash escape character are
     * stripped from the user's term so a caller can't inject wildcards to force
     * a pathological scan; the cleaned term is then wrapped in %…% for a
     * contains search. Stripping (rather than escaping with an ESCAPE clause)
     * keeps this on the structured query builder — no raw SQL — and is correct
     * on every driver, including SQLite, whose LIKE has no default escape
     * character. The trade-off is that a term consisting only of wildcards
     * reduces to an empty needle (a match-all contains), and literal `%`/`_`
     * characters are not searchable; both are acceptable for a contains filter.
     *
     * @param  Builder<Entry>  $query
     *
     * @throws DeliveryException
     */
    private function applyLike(Builder $query, string $column, mixed $value): void
    {
        if (! is_string($value)) {
            throw new DeliveryException("Filter operator 'like' requires a string value.");
        }

        if (mb_strlen($value) > self::MAX_LIKE_LENGTH) {
            throw new DeliveryException("Filter 'like' value must be at most ".self::MAX_LIKE_LENGTH.' characters.');
        }

        $needle = str_replace(['\\', '%', '_'], '', $value);

        $query->where($column, 'like', '%'.$needle.'%');
    }

    /** @return list<string> */
    private function validFilterColumns(ContentType $type): array
    {
        $base = ['id', 'status', 'locale', 'published_at', 'author_id', 'created_at', 'updated_at'];
        $encrypted = $type->encryptedFieldHandles();

        // Encrypted fields store ciphertext — filtering on them would match
        // ciphertext strings, not plaintext, which is both useless and a
        // disclosure risk. Exclude them from the filter allowlist entirely.
        $fieldHandles = array_values(array_filter(
            array_map(fn (Field $f): string => $f->handle, $type->columnFields()),
            fn (string $h): bool => ! in_array($h, $encrypted, true),
        ));

        return array_merge($base, $fieldHandles);
    }
}
