<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Magna\Content\Field;

class JsonField extends FieldType
{
    public function typeName(): string
    {
        return 'json';
    }

    public function isJsonColumn(): bool
    {
        return true;
    }

    public function isRelationOnly(): bool
    {
        return false;
    }

    public function addColumn(Blueprint $table, string $column): void
    {
        // jsonb, not json: Postgres only ships GIN operator classes for jsonb,
        // and TableGenerator puts a GIN index on every JSON column. On MySQL
        // and SQLite the two are the same column type, so this is a
        // Postgres-only change.
        $table->jsonb($column)->nullable();
    }

    /** @return list<string> */
    public function validationRules(): array
    {
        return ['array'];
    }

    public function cast(): ?string
    {
        return 'array';
    }

    /**
     * Prefix of the hidden field holding what a box was loaded with. Field
     * handles must start with a letter, so it can never collide with one.
     */
    public const BASELINE_PREFIX = '_loaded_';

    /**
     * The box holds JSON text; the column holds what that text decodes to.
     *
     * Save writes this field only when the text means something other than
     * what is stored. Re-encoding an untouched value is not a no-op (`{}`
     * comes back as `[]`), and a value the builder owns must not be rewritten
     * by a Save of some unrelated field, so an untouched box stays out of the
     * update altogether.
     *
     * Emptying the box never wipes the column. It is refused while something
     * is stored, because a stray select-and-delete must not cost a page its
     * settings; entering `{}` empties it on purpose. That is a validation rule
     * and not ->required(), which would put the HTML attribute on the box and
     * have the browser stop Save with a generic tooltip before this message.
     *
     * "Untouched" is judged against what the box was LOADED with, kept as a
     * fingerprint in a hidden field that is never saved. Against the fresh
     * row it would go wrong in a tab left open while the page builder changed
     * the value: the old text differs from the new row, so Save would write
     * it back. Now an untouched box is left out however stale it is, and an
     * edit made on top of a value that has since changed is refused rather
     * than allowed to overwrite it.
     */
    public function toFilamentComponent(Field $field): Component
    {
        $label = ucwords(str_replace('_', ' ', $field->handle));
        $baseline = self::BASELINE_PREFIX.$field->handle;
        $stored = static fn (?Model $record): mixed => $record?->getAttribute($field->handle);

        return Group::make([
            Textarea::make($field->handle)
                ->label($label)
                ->required($field->required)
                ->rule('required', fn (?Model $record): bool => filled($stored($record)))
                ->validationMessages($field->required ? [] : [
                    'required' => 'Emptying this box would delete what is stored here. To do that on purpose, enter {} instead.',
                ])
                ->rules([
                    fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        if (self::decode($value) === null) {
                            $fail('Enter valid JSON: an object such as {"key": "value"}, or a list.');
                        }
                    },
                    fn (Get $get, ?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $stored, $baseline, $label): void {
                        $typed = self::decode($value);
                        $loaded = $get($baseline);
                        $current = $stored($record);

                        // Only an edit (text that differs from what was loaded)
                        // on top of a value that has changed since can clash.
                        if ($typed === null || ! is_string($loaded) || $typed === $current
                            || self::fingerprint($typed) === $loaded || self::fingerprint($current) === $loaded
                        ) {
                            return;
                        }

                        $fail($label.' changed somewhere else after this screen was opened, probably in the page builder. Reload to see the new value, then make your change again.');
                    },
                ])
                ->formatStateUsing(static fn (mixed $state): mixed => is_array($state)
                    ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
                    : $state)
                ->dehydrated(static function (mixed $state, Get $get, ?Model $record) use ($stored, $baseline): bool {
                    $typed = self::decode($state);

                    return $typed !== null
                        && $typed !== $stored($record)
                        && self::fingerprint($typed) !== $get($baseline);
                })
                ->dehydrateStateUsing(static fn (mixed $state): ?array => self::decode($state))
                ->rows(6)
                ->hint('JSON')
                ->columnSpanFull(),

            Hidden::make($baseline)
                ->afterStateHydrated(static function (Hidden $component, ?Model $record) use ($stored): void {
                    $component->state(self::fingerprint($stored($record)));
                })
                ->dehydrated(false),
        ])->columnSpanFull();
    }

    /** Strict like ===: key order and int-versus-float both count. */
    private static function fingerprint(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }

    /**
     * What the box's state stands for, or null when it is blank or is not a
     * JSON object or list. Never throws: this also runs on rejected input.
     *
     * @return array<mixed>|null
     */
    private static function decode(mixed $state): ?array
    {
        if (is_array($state)) {
            return $state;
        }

        $decoded = is_string($state) ? json_decode($state, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
