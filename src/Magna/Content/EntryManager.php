<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Magna\Content\Events\EntryCreated;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\FieldTypes\SlugField;
use Magna\Content\Models\Revision;

class EntryManager
{
    /** @var array<string, bool> */
    private array $translationGroupColumnMemo = [];

    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly SchemaValidator $validator,
        private readonly AutoSlugApplier $autoSlugs,
        private readonly EntryRevisionRecorder $revisions,
        private readonly HierarchyMaintainer $hierarchy,
    ) {}

    /**
     * Create a new entry for the given content type.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SchemaException
     * @throws ValidationException
     */
    public function create(string $typeHandle, array $data, ?string $authorId = null): Entry
    {
        $type = $this->resolveType($typeHandle);

        $data = $this->autoSlugs->apply($type, $data);
        $validated = $this->validator->validate($type, $data);
        $validated = $this->prepareForStorage($type, $validated);

        $entry = Entry::makeInstance($typeHandle, $this->registry);
        $entry->fill($validated);
        $entry->locale = isset($data['locale']) && is_string($data['locale']) ? $data['locale'] : '';
        $entry->author_id = $authorId;
        $entry->draft_of = null;

        // A new entry roots its own translation group (§A2). Pre-generate
        // the key so group == id without a second write; translations of
        // this entry will share the group even when their slugs diverge.
        if ($this->hasTranslationGroupColumn($type)) {
            // newUniqueId() (HasUlids) — NOT Str::ulid() directly: the trait
            // lowercases, and lookups (delivery single fetch) normalise ids
            // with strtolower before comparing.
            $entry->id = $entry->newUniqueId();
            $entry->translation_group = $entry->id;
        }

        if ($type->draftable) {
            $entry->status = EntryStatus::Draft;
        } else {
            $entry->status = EntryStatus::Published;
            $entry->published_at = now();
        }

        $this->hierarchy->applyOnSave($entry, $type, $data);

        $entry->save();

        event(new EntryCreated($entry, $authorId));

        if (! $type->draftable) {
            event(new EntryPublished($entry, $authorId));
        }

        return $entry;
    }

    /**
     * Update an existing entry's field values.
     *
     * Only the fields present in $data are validated and updated (partial update).
     * If the entry is currently published, a revision of the pre-update state is
     * saved first.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SchemaException
     * @throws ValidationException
     */
    public function update(Entry $entry, array $data, ?string $actorId = null): Entry
    {
        $type = $this->getType($entry);

        $data = $this->autoSlugs->apply($type, $data, $entry);
        $validated = $this->validator->validate($type, $data, partial: true);
        $validated = $this->prepareForStorage($type, $validated);

        // Stage 13 (S11-02): revision insert + entry save + cross-locale
        // sync are 3 dependent writes — a failure partway previously left
        // whichever ones already committed, e.g. a revision snapshotted
        // but the entry never actually updated, or the entry updated but
        // sibling-locale rows left out of sync.
        DB::transaction(function () use ($entry, $type, $actorId, $validated, $data): void {
            if ($entry->status === EntryStatus::Published) {
                $this->revisions->record($entry, $type->handle, $actorId);
            }

            $entry->fill($validated);
            $this->hierarchy->applyOnSave($entry, $type, $data);
            $entry->save();
            $this->hierarchy->cascadeDescendants($entry, $type);

            // Sync non-localizable fields to all other locale rows (same type + same slug
            // treated as the shared identity for localizable content types).
            $currentType = $this->getType($entry);
            if ($currentType->localizable) {
                $this->syncNonLocalizableFields($entry, $currentType, $validated);
            }
        });

        event(new EntryUpdated($entry, $actorId));

        return $entry;
    }

    /**
     * Publish an entry.
     *
     * - If $at is a future time, the entry is scheduled (status = scheduled).
     * - If the entry's draft_of is set, the published original is updated from
     *   this draft and the draft is deleted.
     * - Otherwise the entry is published immediately.
     *
     * @throws SchemaException
     */
    public function publish(Entry $entry, ?Carbon $at = null, ?string $actorId = null): Entry
    {
        if ($entry->draft_of !== null) {
            return $this->publishDraftOf($entry, $actorId);
        }

        if ($at !== null && $at->isFuture()) {
            $entry->status = EntryStatus::Scheduled;
            $entry->published_at = $at;
            $entry->save();

            return $entry;
        }

        // Stage 13 (S11-02): see update() above — same revision-insert +
        // save atomicity concern.
        DB::transaction(function () use ($entry, $actorId): void {
            if ($entry->status === EntryStatus::Published) {
                $this->revisions->record($entry, $this->getType($entry)->handle, $actorId, Revision::KIND_PUBLISH);
            }

            $entry->status = EntryStatus::Published;
            $entry->published_at = $entry->published_at ?? now();
            $entry->save();
        });

        event(new EntryPublished($entry, $actorId));

        return $entry;
    }

    /**
     * Unpublish (archive) a published entry.
     *
     * @throws SchemaException
     */
    public function unpublish(Entry $entry, ?string $actorId = null): Entry
    {
        $entry->status = EntryStatus::Archived;
        $entry->save();

        event(new EntryUnpublished($entry, $actorId));

        return $entry;
    }

    /**
     * Delete an entry permanently.
     */
    public function delete(Entry $entry, ?string $actorId = null): void
    {
        $copy = clone $entry;
        $handle = $entry->getHandle();
        $id = $entry->id;

        DB::transaction(function () use ($entry, $handle, $id): void {
            $entry->delete();

            // Stage 13 (S5-04): magna_relations can't carry a real foreign
            // key (from_id/to_id point into dynamic magna_entries_{type}
            // tables), so nothing was cleaning up pivot rows referencing a
            // deleted entry — they accumulated forever on both sides
            // (relations *from* this entry, and relations pointing *to*
            // it). RelationLoader already skips a dangling reference
            // safely (confirmed no crash/leak), this just stops the
            // unbounded growth.
            if ($handle !== null) {
                DB::table('magna_relations')
                    ->where(function ($query) use ($handle, $id): void {
                        $query->where('from_type', $handle)->where('from_id', $id);
                    })
                    ->orWhere(function ($query) use ($handle, $id): void {
                        $query->where('to_type', $handle)->where('to_id', $id);
                    })
                    ->delete();

                // Revisions of a hard-deleted entry are unrestorable by
                // construction (restore() findOrFails the live row) — keeping
                // them is unbounded orphan growth, not history. Deleted via
                // the query builder because the model is append-only.
                DB::table('magna_revisions')
                    ->where('entry_type', $handle)
                    ->where('entry_id', $id)
                    ->delete();
            }
        });

        event(new EntryDeleted($copy, $actorId));
    }

    /**
     * Create a draft copy of a published entry.
     *
     * The draft starts with the same field values as the published entry.
     * There should be only one pending draft per published entry at a time;
     * callers are responsible for enforcing this.
     *
     * @throws SchemaException
     */
    public function createDraftOf(Entry $published): Entry
    {
        $handle = $published->getHandle();
        if ($handle === null) {
            throw new SchemaException('Entry is not bound to a content type.');
        }

        $type = $this->resolveType($handle);

        $attrs = [];
        foreach ($type->columnFields() as $field) {
            $attrs[$field->handle] = $published->getAttribute($field->handle);
        }

        $draft = Entry::makeInstance($handle, $this->registry);
        $draft->fill($attrs);

        $locale = $published->getAttribute('locale');
        $draft->locale = is_string($locale) ? $locale : '';

        $authorId = $published->getAttribute('author_id');
        $draft->author_id = is_string($authorId) ? $authorId : null;

        $draft->draft_of = $published->id;
        $draft->status = EntryStatus::Draft;
        $draft->save();

        return $draft;
    }

    /**
     * Restore an entry to the state captured in a revision.
     *
     * Before restoring, the current state is itself snapshotted as a revision
     * so the restore is reversible.
     *
     * @throws SchemaException
     */
    public function restore(string $revisionId, ?string $actorId = null): Entry
    {
        /** @var Revision $revision */
        $revision = Revision::query()->findOrFail($revisionId);

        $handle = $revision->entry_type;
        $type = $this->resolveType($handle);

        /** @var Entry $entry */
        $entry = Entry::type($handle)->findOrFail($revision->entry_id);

        // Stage 13 (S11-02): see update() above — same revision-insert +
        // save atomicity concern.
        DB::transaction(function () use ($entry, $handle, $actorId, $type, $revision): void {
            // Snapshot current state before restoring, so the restore is
            // itself reversible.
            $this->revisions->record($entry, $handle, $actorId, Revision::KIND_RESTORE_POINT);

            $payload = $revision->payload;
            foreach ($type->columnFields() as $field) {
                if (array_key_exists($field->handle, $payload)) {
                    $entry->setAttribute($field->handle, $payload[$field->handle]);
                }
            }
            $entry->save();
        });

        event(new EntryUpdated($entry, $actorId));

        return $entry;
    }

    /**
     * Create a translation of an existing entry for a new locale.
     *
     * Deep-copies all field values (including blocks_data) from the source entry
     * to a new entry row for the given locale. The new entry starts as a Draft.
     *
     * @throws SchemaException
     */
    public function createTranslation(Entry $source, string $targetLocale, ?string $actorId = null): Entry
    {
        $handle = $source->getHandle();
        if ($handle === null) {
            throw new SchemaException('Source entry is not bound to a content type.');
        }

        $type = $this->resolveType($handle);

        if (! $type->localizable) {
            throw new SchemaException("Content type \"{$handle}\" is not localizable.");
        }

        $attrs = [];
        foreach ($type->columnFields() as $field) {
            $attrs[$field->handle] = $source->getAttribute($field->handle);
        }

        $translation = Entry::makeInstance($handle, $this->registry);
        $translation->fill($attrs);
        $translation->locale = $targetLocale;
        $translation->author_id = $actorId;
        $translation->draft_of = null;
        $translation->status = EntryStatus::Draft;
        $translation->published_at = null;
        $translation->unpublish_at = null;

        // Locale variants share one translation group. A pre-backfill source
        // (null group) is adopted into a group rooted at its own id.
        if ($this->hasTranslationGroupColumn($type)) {
            $sourceGroup = $source->getAttribute('translation_group');
            if (! is_string($sourceGroup) || $sourceGroup === '') {
                $sourceKey = $source->getKey();
                $sourceGroup = is_string($sourceKey) ? $sourceKey : $source->newUniqueId();
                $source->translation_group = $sourceGroup;
                $source->save();
            }
            $translation->translation_group = $sourceGroup;
        }

        $translation->save();

        event(new EntryCreated($translation, $actorId));

        return $translation;
    }

    /**
     * Schedule automatic unpublishing at a given time.
     *
     * Sets unpublish_at on the entry. The magna:publish:scheduled command
     * checks this column and calls unpublish() when the time is reached.
     */
    public function scheduleUnpublish(Entry $entry, Carbon $at, ?string $actorId = null): Entry
    {
        $entry->unpublish_at = $at;
        $entry->save();

        return $entry;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Propagate non-localizable field values to all other locale rows of the same entry.
     *
     * Locale identity: entries of the same type sharing the same slug value are
     * considered locale variants of the same content item.
     *
     * @param  array<string, mixed>  $updatedData  The validated data that was just written.
     */
    private function syncNonLocalizableFields(Entry $entry, ContentType $type, array $updatedData): void
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

    /**
     * Whether the type's table carries translation_group yet (older installs
     * must run magna:content:add-translation-groups). Memoized per handle —
     * this sits on the entry-save hot path.
     */
    private function hasTranslationGroupColumn(ContentType $type): bool
    {
        return $this->translationGroupColumnMemo[$type->handle]
            ??= Schema::hasColumn($type->tableName(), 'translation_group');
    }

    private function publishDraftOf(Entry $draft, ?string $actorId): Entry
    {
        $handle = $draft->getHandle();
        if ($handle === null) {
            throw new SchemaException('Draft entry is not bound to a content type.');
        }

        $type = $this->resolveType($handle);

        $draftOf = $draft->draft_of;
        if (! is_string($draftOf)) {
            throw new SchemaException('Draft entry has no valid draft_of reference.');
        }

        /** @var Entry $published */
        $published = Entry::type($handle)->findOrFail($draftOf);

        // Stage 11 (S11-01): a revision insert, the published-entry update,
        // and the draft delete are three dependent writes — if the delete
        // failed after the save succeeded (DB blip, deadlock), the draft
        // row would survive pointing at content already applied to the
        // published entry, letting it be re-opened/re-published as if
        // still pending. Wrapped so a mid-sequence failure leaves nothing
        // committed instead of a half-applied state.
        DB::transaction(function () use ($published, $handle, $actorId, $type, $draft): void {
            // Snapshot the published state before overwriting it.
            $this->revisions->record($published, $handle, $actorId, Revision::KIND_PUBLISH);

            // Copy all field values from the draft to the published entry.
            foreach ($type->columnFields() as $field) {
                $published->setAttribute($field->handle, $draft->getAttribute($field->handle));
            }
            $published->published_at = now();
            $published->save();

            // Remove the draft.
            $draft->delete();
        });

        event(new EntryPublished($published, $actorId));

        return $published;
    }

    private function getType(Entry $entry): ContentType
    {
        $handle = $entry->getHandle();
        if ($handle === null) {
            throw new SchemaException('Entry is not bound to a content type.');
        }

        return $this->resolveType($handle);
    }

    /**
     * Resolve a registered content type by handle, or fail.
     *
     * The single place the "unknown content type" guard lives — every
     * lifecycle method that starts from a type handle routes through here
     * instead of repeating the registry lookup + null check.
     *
     * @throws SchemaException
     */
    private function resolveType(string $handle): ContentType
    {
        $type = $this->registry->get($handle);
        if ($type === null) {
            throw new SchemaException("Unknown content type: \"{$handle}\".");
        }

        return $type;
    }

    /**
     * Run each field's storage transform (sanitization/normalization) over
     * the validated payload — see FieldType::prepareForStorage().
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function prepareForStorage(ContentType $type, array $validated): array
    {
        foreach ($type->fields as $field) {
            if (array_key_exists($field->handle, $validated)) {
                $validated[$field->handle] = $field->type->prepareForStorage($validated[$field->handle]);
            }
        }

        return $validated;
    }
}
