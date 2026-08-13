<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Content\Exceptions\SchemaException;
use Magna\Content\FieldTypes\FieldType;

final class Field
{
    /**
     * @param  array<string, mixed>  $rawData  Original JSON data — used for round-trip serialisation.
     */
    public function __construct(
        public readonly string $handle,
        public readonly FieldType $type,
        public readonly bool $required,
        public readonly bool $encrypted,
        public readonly array $rawData,
        /**
         * Whether this field stores different values per locale.
         * Non-localizable fields (localizable: false) sync their value across
         * all locale rows whenever any locale of the entry is saved.
         */
        public readonly bool $localizable = true,
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
            throw new SchemaException('Field "handle" must be a non-empty string.');
        }

        // S1-08: field handles are used as raw SQL column/index identifiers in
        // TableGenerator (e.g. addGinIndex() string-concatenates $column into
        // `CREATE INDEX ... ({$column})`). Unlike ContentType handles, this
        // was previously unvalidated at the domain layer — only the Filament
        // admin widget enforced a format, which the Management REST API and
        // artisan commands bypass entirely. Enforce the same allowlist here,
        // at the one place every entry point (API, CLI, plugins) passes
        // through, plus a length cap matching MySQL/Postgres identifier limits.
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $handle)) {
            throw new SchemaException("Field handle \"{$handle}\" must be lowercase alphanumeric with underscores, starting with a letter.");
        }
        if (strlen($handle) > 63) {
            throw new SchemaException("Field handle \"{$handle}\" exceeds the 63-character identifier limit.");
        }

        $typeName = $data['type'] ?? null;
        if (! is_string($typeName) || $typeName === '') {
            throw new SchemaException("Field \"{$handle}\" must specify a \"type\".");
        }

        $required = isset($data['required']) && (bool) $data['required'];
        $encrypted = isset($data['encrypted']) && (bool) $data['encrypted'];
        $localizable = ! isset($data['localizable']) || (bool) $data['localizable'];

        // Extract options: all keys except the reserved ones, narrowed to string keys.
        $reserved = ['handle', 'type', 'required'];
        $options = [];
        $rawData = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $rawData[$key] = $value;
            if (! in_array($key, $reserved, true)) {
                $options[$key] = $value;
            }
        }

        return new self(
            handle: $handle,
            type: $registry->make($typeName, $options),
            required: $required,
            encrypted: $encrypted,
            rawData: $rawData,
            localizable: $localizable,
        );
    }

    /**
     * §C4 field-level exposure: whether this field's value may appear on
     * the public site (collection templates, bindings). Default OFF — PII
     * and internal fields stay private unless the schema says otherwise;
     * encrypted fields are never public regardless of the flag.
     */
    public function publicOnFrontend(): bool
    {
        return ! $this->encrypted && ($this->rawData['publicOnFrontend'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->rawData;
    }
}
