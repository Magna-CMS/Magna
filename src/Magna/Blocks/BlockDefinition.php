<?php

declare(strict_types=1);

namespace Magna\Blocks;

/**
 * Value object representing one registered block type loaded from block.json.
 */
final class BlockDefinition
{
    /**
     * @param  list<BlockField>  $fields
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $icon,
        public readonly string $category,
        public readonly array $fields,
    ) {}

    /**
     * @param  array<mixed, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $handle = isset($data['handle']) && is_string($data['handle']) ? $data['handle'] : '';
        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $handle)) {
            throw new \InvalidArgumentException("Block handle \"{$handle}\" must be lowercase alphanumeric with hyphens/underscores.");
        }

        $label = isset($data['label']) && is_string($data['label'])
            ? $data['label']
            : ucwords(str_replace(['-', '_'], ' ', $handle));
        $icon = isset($data['icon']) && is_string($data['icon']) ? $data['icon'] : 'heroicon-o-squares-2x2';
        $category = isset($data['category']) && is_string($data['category']) ? $data['category'] : 'content';

        $fields = [];
        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $fieldData) {
                if (is_array($fieldData)) {
                    $fields[] = BlockField::fromArray($fieldData);
                }
            }
        }

        return new self(
            handle: $handle,
            label: $label,
            icon: $icon,
            category: $category,
            fields: $fields,
        );
    }

    /**
     * Load a BlockDefinition from a block.json file path.
     */
    public static function fromFile(string $path): self
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read block definition file: {$path}");
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid JSON in block definition: {$path}");
        }

        return self::fromArray($data);
    }

    /**
     * Return the field with the given handle, or null if not found.
     */
    public function field(string $handle): ?BlockField
    {
        foreach ($this->fields as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Validate a block data payload against this definition's field rules.
     * Returns a map of field handle → error messages (empty map = valid).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function validate(array $data): array
    {
        $errors = [];
        foreach ($this->fields as $field) {
            $value = $data[$field->handle] ?? null;

            // A field binding ({"$bind": "entry.title"}) resolves at render
            // time and satisfies requiredness — validating the literal here
            // would false-fail every bound field.
            if (is_array($value) && array_key_exists('$bind', $value)) {
                continue;
            }

            if ($field->required && ($value === null || $value === '' || $value === [])) {
                $errors[$field->handle][] = "The {$field->label} field is required.";

                continue;
            }

            if ($value !== null) {
                foreach ($field->validateValue($value) as $message) {
                    $errors[$field->handle][] = $message;
                }
            }
        }

        return $errors;
    }
}
