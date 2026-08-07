<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Magna\Content\Exceptions\SchemaException;

/**
 * Dynamic Eloquent model for content entries.
 *
 * Use Entry::type('article') to get a Builder bound to magna_entries_article
 * with attribute casts derived from the schema's FieldTypes.
 *
 * @property string $id
 * @property EntryStatus $status
 * @property string $locale
 * @property string|null $translation_group
 * @property string|null $parent_id
 * @property int $position
 * @property string|null $path
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublish_at
 * @property string|null $author_id
 * @property string|null $draft_of
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Entry extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected ?string $contentTypeHandle = null;

    /** @var array<string, string> */
    protected array $schemaCasts = [];

    /**
     * Return a query builder bound to the entry table for the given content type.
     *
     * @return Builder<static>
     */
    public static function type(string $handle): Builder
    {
        /** @var SchemaRegistry $registry */
        $registry = app(SchemaRegistry::class);

        return self::makeInstance($handle, $registry)->newQuery();
    }

    /**
     * Build a fully-configured Entry instance for the given content type
     * (correct table, casts from schema). Use this when you need the instance
     * itself rather than a query builder.
     */
    public static function makeInstance(string $handle, SchemaRegistry $registry): static
    {
        $type = $registry->get($handle);
        if ($type === null) {
            throw new SchemaException("Unknown content type: \"{$handle}\".");
        }

        $instance = new self;
        $instance->contentTypeHandle = $handle;
        $instance->setTable($type->tableName());

        foreach ($type->columnFields() as $field) {
            if ($field->encrypted) {
                // encrypted cast supersedes the field type's own cast; the DB
                // column stores the serialised ciphertext from Laravel's encrypt().
                $instance->schemaCasts[$field->handle] = 'encrypted';
            } else {
                $cast = $field->type->cast();
                if ($cast !== null) {
                    $instance->schemaCasts[$field->handle] = $cast;
                }
            }
        }

        // initializeHasAttributes() merges casts() before schemaCasts is populated,
        // so call mergeCasts() here to ensure JSON/array fields are properly cast on save.
        $instance->mergeCasts($instance->schemaCasts);

        return $instance;
    }

    /**
     * Propagate table and schema casts to every instance Eloquent hydrates
     * from query results.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newInstance($attributes = [], $exists = false): static
    {
        $model = parent::newInstance($attributes, $exists);

        if ($this->contentTypeHandle !== null) {
            $model->contentTypeHandle = $this->contentTypeHandle;
            $model->setTable($this->getTable());
            $model->schemaCasts = $this->schemaCasts;
        }

        return $model;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge([
            'status' => EntryStatus::class,
            'published_at' => 'datetime',
            'unpublish_at' => 'datetime',
        ], $this->schemaCasts);
    }

    public function getHandle(): ?string
    {
        return $this->contentTypeHandle;
    }

    public function isPublished(): bool
    {
        return $this->status === EntryStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === EntryStatus::Draft || $this->status === EntryStatus::Scheduled;
    }
}
