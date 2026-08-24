<?php

declare(strict_types=1);

namespace Magna\Blocks;

use Magna\Blocks\Contracts\ProvidesOptions;

/**
 * Represents one field definition inside a block.json schema.
 *
 * @phpstan-type BlockFieldArray array{
 *   handle: string,
 *   type: string,
 *   label?: string,
 *   required?: bool,
 *   default?: mixed,
 *   options?: array<string, string>,
 *   optionsFrom?: string,
 *   multiple?: bool,
 *   fields?: list<mixed>,
 *   accept?: string,
 * }
 */
final class BlockField
{
    /** Repeater safety cap — a document field is not a data import. */
    public const MAX_REPEATER_ITEMS = 100;

    /** Pictures only — what every media field meant before `accept` existed. */
    public const ACCEPT_IMAGE = 'image';

    /** Any file the install's ingest allowlist accepts. */
    public const ACCEPT_ANY = 'any';

    public function __construct(
        public readonly string $handle,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required,
        public readonly mixed $default,
        /** @var array<string, string> */
        public readonly array $options,
        public readonly ?string $optionsFrom,
        public readonly bool $multiple,
        /** @var list<BlockField> Item fields when type === 'repeater' */
        public readonly array $fields = [],
        /**
         * Which tab of the inspector this field belongs on.
         *
         * A block decides how its own settings are grouped, the way it
         * already decides what they are. Absent means "Content", which is
         * where every field lived before this existed — so no block.json
         * needs changing and nothing moves for a block that says nothing.
         */
        public readonly ?string $group = null,
        /**
         * What a `media` field will let an editor choose.
         *
         * `image` is the default because every media field that existed
         * before this one was a picture, and a picker that started
         * offering PDFs to the logo field would be a regression. `any`
         * exists for the blocks that want a file rather than an image —
         * a download link, an attachment.
         *
         * Bounded by the ingest allowlist either way: this decides what
         * the picker OFFERS, not what the install accepts.
         */
        public readonly string $accept = self::ACCEPT_IMAGE,
    ) {}

    /**
     * @param  array<mixed, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $handle = isset($data['handle']) && is_string($data['handle']) ? $data['handle'] : '';
        if ($handle === '') {
            throw new \InvalidArgumentException('Block field must have a non-empty "handle".');
        }

        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : 'text';

        /** @var array<string, string> $options */
        $options = [];
        if (isset($data['options']) && is_array($data['options'])) {
            foreach ($data['options'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $options[$k] = $v;
                }
            }
        }

        $optionsFrom = isset($data['optionsFrom']) && is_string($data['optionsFrom'])
            ? $data['optionsFrom']
            : null;

        $itemFields = [];
        if ($type === 'repeater' && isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $itemFieldRaw) {
                if (is_array($itemFieldRaw)) {
                    $itemField = self::fromArray($itemFieldRaw);
                    if ($itemField->type === 'repeater') {
                        throw new \InvalidArgumentException(
                            "Repeater field \"{$handle}\" must not nest another repeater."
                        );
                    }
                    $itemFields[] = $itemField;
                }
            }
        }

        return new self(
            handle: $handle,
            type: $type,
            label: isset($data['label']) && is_string($data['label']) ? $data['label'] : ucwords(str_replace('_', ' ', $handle)),
            required: isset($data['required']) && (bool) $data['required'],
            default: $data['default'] ?? null,
            options: $options,
            optionsFrom: $optionsFrom,
            multiple: isset($data['multiple']) && (bool) $data['multiple'],
            fields: $itemFields,
            group: isset($data['group']) && is_string($data['group']) && $data['group'] !== ''
                ? $data['group']
                : null,
            // An unrecognised value falls back to pictures rather than
            // widening the picker: a typo in a block.json must not be the
            // thing that starts offering an editor every file on the site.
            accept: ($data['accept'] ?? null) === self::ACCEPT_ANY
                ? self::ACCEPT_ANY
                : self::ACCEPT_IMAGE,
        );
    }

    /**
     * Validate a present, non-bound value against this field's type rules.
     * Requiredness and `$bind` handling live in BlockDefinition::validate();
     * only the new typed fields validate values — legacy free-form types
     * (text, textarea, richtext, json, select, number, media, url) keep
     * their historical tolerance so existing stored content never starts
     * failing saves retroactively.
     *
     * @return list<string>
     */
    public function validateValue(mixed $value): array
    {
        return match ($this->type) {
            'alignment' => in_array($value, ['left', 'center', 'right'], true)
                ? []
                : ["The {$this->label} field must be left, center, or right."],
            'color' => $this->validateColor($value),
            'icon' => is_string($value) && preg_match('/^[a-z0-9:.-]+$/', $value) === 1
                ? []
                : ["The {$this->label} field must be an icon name (lowercase letters, digits, dashes)."],
            'link' => $this->validateLink($value),
            'repeater' => $this->validateRepeater($value),
            default => [],
        };
    }

    /** @return list<string> */
    private function validateColor(mixed $value): array
    {
        $valid = is_string($value) && (
            preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1
            || preg_match('/^token:[a-z][a-z0-9-]*$/', $value) === 1
        );

        return $valid
            ? []
            : ["The {$this->label} field must be a hex color (#rrggbb) or a design-token reference (token:name)."];
    }

    /** @return list<string> */
    private function validateLink(mixed $value): array
    {
        // Entry-reference shape (the builder's page/entry link picker).
        if (is_array($value)) {
            $type = $value['entry_type'] ?? null;
            $id = $value['entry_id'] ?? null;

            return is_string($type) && $type !== '' && is_string($id) && $id !== ''
                ? []
                : ["The {$this->label} field's entry link needs entry_type and entry_id."];
        }

        if (! is_string($value) || $value === '') {
            return ["The {$this->label} field must be a URL or an entry link."];
        }

        // Relative paths and fragments are fine; absolute URLs must carry an
        // allowed scheme (matches Support\SafeUrl).
        if (str_starts_with($value, '/') || str_starts_with($value, '#')) {
            return [];
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)
            ? []
            : ["The {$this->label} field's URL scheme must be http, https, mailto, or tel."];
    }

    /** @return list<string> */
    private function validateRepeater(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ["The {$this->label} field must be a list of items."];
        }

        if (count($value) > self::MAX_REPEATER_ITEMS) {
            return ["The {$this->label} field exceeds ".self::MAX_REPEATER_ITEMS.' items.'];
        }

        $errors = [];
        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "The {$this->label} field's item #{$index} must be an object.";

                continue;
            }

            foreach ($this->fields as $itemField) {
                $itemValue = $item[$itemField->handle] ?? null;

                if (is_array($itemValue) && array_key_exists('$bind', $itemValue)) {
                    continue;
                }

                if ($itemField->required && ($itemValue === null || $itemValue === '' || $itemValue === [])) {
                    $errors[] = "The {$this->label} field's item #{$index} is missing {$itemField->label}.";

                    continue;
                }

                if ($itemValue !== null) {
                    foreach ($itemField->validateValue($itemValue) as $message) {
                        $errors[] = "The {$this->label} field's item #{$index}: {$message}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve dynamic options from the `optionsFrom` class at form render time.
     * Falls back to the static `options` array when `optionsFrom` is not set.
     *
     * @return array<string, string>
     */
    public function resolveOptions(): array
    {
        if ($this->optionsFrom !== null
            && class_exists($this->optionsFrom)
            && is_a($this->optionsFrom, ProvidesOptions::class, true)
        ) {
            return app($this->optionsFrom)->options();
        }

        return $this->options;
    }
}
