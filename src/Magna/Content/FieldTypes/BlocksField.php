<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Filament\Actions\Action;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Magna\Blocks\Rules\ValidBlockDocument;
use Magna\Blocks\Sanitization\RichTextSanitizer;
use Magna\Content\Entry;
use Magna\Content\Field;

class BlocksField extends FieldType
{
    /** Registered by magna-cms/pages, so it exists only while that plugin does. */
    private const BUILDER_ROUTE = 'pages.builder.edit';

    /** What that builder opens; any other type would 404 there. */
    private const BUILDER_TYPES = ['page', 'pages_template'];

    public function typeName(): string
    {
        return 'blocks';
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
        // jsonb — see JsonField::addColumn() for why.
        $table->jsonb($column)->nullable();
    }

    /** @return list<string|ValidationRule> */
    public function validationRules(): array
    {
        // Structural document validation + raw-HTML authorization on every
        // save path — not just the preview endpoint. See ValidBlockDocument.
        return ['array', new ValidBlockDocument];
    }

    public function cast(): ?string
    {
        return 'array';
    }

    /**
     * Sanitize richtext block bodies at rest (defense in depth alongside
     * render-time sanitization — 10-REVIEW-RESOLUTIONS §C1). Handles both
     * document shapes and nested children; everything it does not
     * understand passes through untouched (tolerant-reader rule).
     */
    public function prepareForStorage(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        /** @var RichTextSanitizer $sanitizer */
        $sanitizer = app(RichTextSanitizer::class);

        // Wrapped form: {"schemaVersion": ..., "sections": [...]}.
        if (! array_is_list($value) && is_array($value['sections'] ?? null)) {
            $value['sections'] = $this->sanitizeSections($value['sections'], $sanitizer);

            return $value;
        }

        return $this->sanitizeSections($value, $sanitizer);
    }

    /**
     * @param  array<mixed, mixed>  $sections
     * @return array<mixed, mixed>
     */
    private function sanitizeSections(array $sections, RichTextSanitizer $sanitizer): array
    {
        foreach ($sections as $i => $section) {
            if (is_array($section) && is_array($section['columns'] ?? null)) {
                foreach ($section['columns'] as $j => $column) {
                    if (is_array($column) && is_array($column['blocks'] ?? null)) {
                        $column['blocks'] = $this->sanitizeBlocks($column['blocks'], $sanitizer);
                        $section['columns'][$j] = $column;
                    }
                }
                $sections[$i] = $section;
            }
        }

        return $sections;
    }

    /**
     * @param  array<mixed, mixed>  $blocks
     * @return array<mixed, mixed>
     */
    private function sanitizeBlocks(array $blocks, RichTextSanitizer $sanitizer): array
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['block'] ?? null) === 'text'
                && is_array($block['data'] ?? null)
                && is_string($block['data']['body'] ?? null)
            ) {
                $block['data']['body'] = $sanitizer->sanitize($block['data']['body']);
            }

            if (is_array($block['children'] ?? null)) {
                $block['children'] = $this->sanitizeBlocks($block['children'], $sanitizer);
            }

            $blocks[$i] = $block;
        }

        return $blocks;
    }

    /**
     * A pointer, not an editor, and it must stay one. An unbound editor opened
     * empty on pages that had sections and said "Saved" while storing nothing;
     * a bound one would send the whole document back through every Save. With
     * no state path here, Save cannot touch the column at all.
     */
    public function toFilamentComponent(Field $field): Component
    {
        return Callout::make('Sections are not edited on this screen')
            ->key('blocks-notice-'.$field->handle)
            ->info()
            ->description(fn (?Model $record): string => $this->builderUrl($record) !== null
                ? 'Open the page builder to change them. Saving here leaves them exactly as they are.'
                : 'The page builder in Magna Pages edits them. Saving here leaves them exactly as they are.')
            ->actions([
                Action::make('openBuilder_'.$field->handle)
                    ->label('Open page builder')
                    ->url(fn (?Model $record): ?string => $this->builderUrl($record))
                    ->visible(fn (?Model $record): bool => $this->builderUrl($record) !== null),
            ])
            ->columnSpanFull();
    }

    private function builderUrl(?Model $record): ?string
    {
        if (! $record instanceof Entry
            || ! in_array($record->getHandle(), self::BUILDER_TYPES, true)
            || ! Route::has(self::BUILDER_ROUTE)
        ) {
            return null;
        }

        return route(self::BUILDER_ROUTE, ['id' => $record->getKey()]);
    }
}
