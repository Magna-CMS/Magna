<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Magna\Content\FieldTypes\SlugField;
use Magna\Settings\LocalizationSettings;

/**
 * Everything EntryManager needs to know about an entry's locale: which
 * locale strings are storable, whether a type's table carries the
 * translation_group column yet, and how a change to a non-localizable field
 * fans out to the entry's other locale rows.
 *
 * Extracted from EntryManager per the collaborator pattern (see how
 * PluginManager was split): the manager orchestrates the entry lifecycle
 * and delegates the locale mechanics here.
 */
final class EntryLocales
{
    /** @var array<string, bool> */
    private array $translationGroupColumnMemo = [];

    /**
     * A locale is caller-supplied ($request->all() reaches EntryManager
     * whole) and used to be stored verbatim — any string at all became a
     * permanent row discriminator that the admin UI, locale fallback chains
     * and cross-locale sync then had to make sense of. Empty stays empty
     * (the non-localized case); anything else must be one of the locales
     * the admin actually enabled.
     */
    public function resolve(mixed $locale): string
    {
        if (! is_string($locale) || $locale === '') {
            return '';
        }

        $available = LocalizationSettings::get()->available_locales;

        if (! in_array($locale, $available, true)) {
            throw ValidationException::withMessages([
                'locale' => "Locale '{$locale}' is not enabled on this site.",
            ]);
        }

        return $locale;
    }

    /**
     * Whether the type's table carries translation_group yet (older installs
     * must run magna:content:add-translation-groups). Memoized per handle —
     * this sits on the entry-save hot path.
     */
    public function hasTranslationGroupColumn(ContentType $type): bool
    {
        return $this->translationGroupColumnMemo[$type->handle]
            ??= Schema::hasColumn($type->tableName(), 'translation_group');
    }

    /**
     * Propagate non-localizable field values to all other locale rows of the
     * same entry.
     *
     * @param  array<string, mixed>  $updatedData  The validated data that was just written.
     */
    public function syncNonLocalizable(Entry $entry, ContentType $type, array $updatedData): void
    {
        $nonLocalizable = $type->nonLocalizableFields();
        if ($nonLocalizable === []) {
            return;
        }

        // Collect updates for the non-localizable fields that were actually changed.
        $syncData = [];
        foreach ($nonLocalizable as $field) {
            if (array_key_exists($field->handle, $updatedData)) {
                $syncData[$field->handle] = $updatedData[$field->handle];
            }
        }

        if ($syncData === []) {
            return;
        }

        // Locale-variant identity: translation_group when available (§A2 —
        // survives translated slugs), falling back to the legacy same-slug
        // convention only for rows the backfill command has not touched yet.
        $group = $entry->getAttribute('translation_group');
        [$identityColumn, $identityValue] = is_string($group) && $group !== ''
            ? ['translation_group', $group]
            : $this->legacySlugIdentity($entry, $type);

        if ($identityColumn === null || ! is_string($identityValue) || $identityValue === '') {
            return;
        }

        $entryLocale = is_string($entry->getAttribute('locale')) ? $entry->getAttribute('locale') : '';

        // Wrap in a transaction: multiple locale variants are common and each save
        // would otherwise auto-commit individually, causing unnecessary round-trips.
        DB::transaction(function () use ($type, $identityColumn, $identityValue, $entryLocale, $syncData): void {
            Entry::type($type->handle)
                ->where($identityColumn, $identityValue)
                ->where('locale', '!=', $entryLocale)
                ->get()
                ->each(function (Entry $other) use ($syncData): void {
                    $other->fill($syncData);
                    $other->save();
                });
        });
    }

    /**
     * The pre-translation_group identity: first slug field + its value.
     *
     * @return array{0: string|null, 1: mixed}
     */
    private function legacySlugIdentity(Entry $entry, ContentType $type): array
    {
        foreach ($type->fields as $field) {
            if ($field->type instanceof SlugField) {
                return [$field->handle, $entry->getAttribute($field->handle)];
            }
        }

        return [null, null];
    }
}
