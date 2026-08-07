<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Content\Exceptions\SchemaException;

final class ContentType
{
    /** @param list<Field> $fields */
    public function __construct(
        public readonly string $handle,
        public readonly string $displayName,
        public readonly bool $localizable,
        public readonly bool $draftable,
        public readonly array $fields,
        /**
         * Hierarchical types get structural columns (parent_id, position,
         * path) on their generated table; the path column is the joined
         * ancestor slugs and drives nested URL resolution.
         */
        public readonly bool $hierarchical = false,
    ) {}

    /**
     * @param  array<mixed, mixed>  $data
     *
     * @throws SchemaException
     */
    public static function fromArray(array $data, FieldTypeRegistry $registry): self
    {
        $handle = $data['handle'] ?? null;
        if (! is_string($handle) || $handle === '') {
            throw new SchemaException('Content type "handle" must be a non-empty string.');
        }

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
            throw new SchemaException("Content type handle \"{$handle}\" must be lowercase alphanumeric with underscores.");
        }

        $displayName = $data['displayName'] ?? null;
        if (! is_string($displayName) || $displayName === '') {
            throw new SchemaException("Content type \"{$handle}\" must have a non-empty \"displayName\".");
        }

        $localizable = isset($data['localizable']) && (bool) $data['localizable'];
        $draftable = ! isset($data['draftable']) || (bool) $data['draftable'];

        $fieldsRaw = $data['fields'] ?? [];
        if (! is_array($fieldsRaw)) {
            throw new SchemaException("Content type \"{$handle}\" \"fields\" must be an array.");
        }

        $reserved = ['id', 'status', 'locale', 'translation_group', 'published_at', 'unpublish_at', 'author_id', 'draft_of', 'created_at', 'updated_at', 'parent_id', 'position', 'path'];

        /** @var list<Field> $fields */
        $fields = [];
        foreach ($fieldsRaw as $fieldData) {
            if (! is_array($fieldData)) {
                throw new SchemaException("Each field in \"{$handle}\" must be an object.");
            }
            $field = Field::fromArray($fieldData, $registry);
            if (in_array($field->handle, $reserved, true)) {
                throw new SchemaException("Field handle \"{$field->handle}\" in \"{$handle}\" conflicts with a reserved column name.");
            }
            $fields[] = $field;
        }

        return new self(
            handle: $handle,
            displayName: $displayName,
            localizable: $localizable,
            draftable: $draftable,
            fields: $fields,
            hierarchical: isset($data['hierarchical']) && (bool) $data['hierarchical'],
        );
    }

    /**
     * @throws SchemaException
     */
    public static function fromFile(string $path, FieldTypeRegistry $registry): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new SchemaException("Cannot read schema file: {$path}");
        }

        $data = json_decode($contents, true);
        if (! is_array($data)) {
            throw new SchemaException("Schema file is not valid JSON: {$path}");
        }

        return self::fromArray($data, $registry);
    }

    public function tableName(): string
    {
        return 'magna_entries_'.$this->handle;
    }

    public function getField(string $handle): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Fields that need a physical column in the entry table (excludes relation-only fields).
     *
     * @return list<Field>
     */
    public function columnFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $f): bool => ! $f->type->isRelationOnly(),
        ));
    }

    /**
     * Fields with localizable: false — their value syncs across all locale rows
     * of this content type when any locale is saved.
     *
     * @return list<Field>
     */
    public function nonLocalizableFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $f): bool => ! $f->localizable && ! $f->type->isRelationOnly(),
        ));
    }

    /**
     * Field handles that are marked encrypted=true in the schema.
     * These fields must never appear in delivery API filters or search indexes.
     *
     * @return list<string>
     */
    public function encryptedFieldHandles(): array
    {
        $handles = [];
        foreach ($this->fields as $field) {
            if ($field->encrypted) {
                $handles[] = $field->handle;
            }
        }

        return $handles;
    }

    /**
     * Field handles that map to JSON/JSONB columns.
     *
     * @return list<string>
     */
    public function jsonColumnHandles(): array
    {
        $handles = [];
        foreach ($this->fields as $field) {
            if (! $field->type->isRelationOnly() && $field->type->isJsonColumn()) {
                $handles[] = $field->handle;
            }
        }

        return $handles;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'displayName' => $this->displayName,
            'localizable' => $this->localizable,
            'draftable' => $this->draftable,
            'hierarchical' => $this->hierarchical,
            'fields' => array_map(fn (Field $f): array => $f->toArray(), $this->fields),
        ];
    }
}
