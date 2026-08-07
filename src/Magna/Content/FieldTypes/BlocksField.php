<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Schema\Blueprint;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Blocks\Rules\ValidBlockDocument;
use Magna\Blocks\Sanitization\RichTextSanitizer;
use Magna\Content\Field;

class BlocksField extends FieldType
{
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

    public function toFilamentComponent(Field $field): Component
    {
        return Livewire::make(BlockEditor::class)
            ->key('block-editor-'.$field->handle)
            ->columnSpanFull();
    }
}
