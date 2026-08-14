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
     * @param  list<string>  $inlineFields  Field handles this block exposes for editing on the
     *                                      canvas, in the order it wants them offered. Empty
     *                                      means "the first eligible field", which is what every
     *                                      block did before the declaration existed.
     * @param  string|null  $requiresPermission  Permission needed to insert this block, if any
     * @param  string|null  $sourcePlugin  Plugin that registered this block (null = core).
     *                                     Stamped by the contract wirer, never by block.json —
     *                                     provenance is a fact about registration, not a claim
     *                                     a definition file gets to make about itself.
     * @param  bool  $container  Whether this block holds nested blocks in `children`
     *                           (docs/magna-pages/02-BLOCK-SYSTEM.md §"Nested layout blocks").
     *                           Declared per block rather than hardcoded by handle, so a
     *                           plugin's own layout block nests on the same terms core's does.
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $icon,
        public readonly string $category,
        public readonly array $fields,
        public readonly array $inlineFields = [],
        public readonly ?string $requiresPermission = null,
        public readonly ?string $sourcePlugin = null,
        public readonly bool $container = false,
    ) {}

    /** An identical definition attributed to the given plugin. */
    public function withSourcePlugin(string $plugin): self
    {
        return new self(
            handle: $this->handle,
            label: $this->label,
            icon: $this->icon,
            category: $this->category,
            fields: $this->fields,
            inlineFields: $this->inlineFields,
            requiresPermission: $this->requiresPermission,
            sourcePlugin: $plugin,
            container: $this->container,
        );
    }

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

        // Which fields may be edited on the canvas. A handle naming a field
        // this block does not have is dropped here rather than shipped to a
        // builder that would then look for it.
        $handles = array_map(static fn (BlockField $field): string => $field->handle, $fields);
        $inlineFields = [];
        if (isset($data['inlineFields']) && is_array($data['inlineFields'])) {
            foreach ($data['inlineFields'] as $candidate) {
                if (is_string($candidate) && in_array($candidate, $handles, true)) {
                    $inlineFields[] = $candidate;
                }
            }
        }

        // Declared per block rather than hardcoded by handle in the
        // authorizer: a plugin's own dangerous block needs the same gate the
        // core `html` block gets, and only its definition knows that.
        $requiresPermission = isset($data['requiresPermission']) && is_string($data['requiresPermission']) && $data['requiresPermission'] !== ''
            ? $data['requiresPermission']
            : null;

        return new self(
            handle: $handle,
            label: $label,
            icon: $icon,
            category: $category,
            fields: $fields,
            inlineFields: $inlineFields,
            requiresPermission: $requiresPermission,
            container: ($data['container'] ?? false) === true,
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
