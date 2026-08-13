<?php

declare(strict_types=1);

namespace Magna\Blocks\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;
use Livewire\Component;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Contracts\GuardsDocumentEdits;
use Magna\Media\Media;
use Magna\Media\MediaUrlResolver;
use RuntimeException;

/**
 * Livewire block editor component for the Section → Column → Block page tree.
 *
 * Embedded in the Filament entry form as a custom field.  The host form passes
 * the current blocks_data JSON string via the @modelable wire:model binding.
 *
 * Usage in Filament form schema:
 *
 *   \Filament\Forms\Components\Livewire::make(BlockEditor::class)
 *       ->key('block-editor')
 *
 * The component fires 'blockEditorUpdated' (dispatch to parent) with the
 * serialised blocks_data whenever a save is triggered.
 *
 * @phpstan-type TokenOverride array{key: string, value: string}
 * @phpstan-type BlockEntry array{id: string, block: string, settings: array<string, mixed>, data: array<string, mixed>}
 * @phpstan-type ColumnEntry array{id: string, span: int, settings: array<string, mixed>, blocks: list<BlockEntry>}
 * @phpstan-type SectionSettings array{tokenOverrides: list<TokenOverride>, background?: array<string, mixed>, padding?: array<string, mixed>, maxWidth?: string, anchor?: string, visibility?: array<string, mixed>, cssClass?: string}
 * @phpstan-type SectionEntry array{id: string, type: string, settings: SectionSettings, columns: list<ColumnEntry>}
 */
class BlockEditor extends Component
{
    /**
     * The serialised blocks_data JSON string.
     * Marked @modelable so Filament's Livewire form component can bind the field
     * value to this property via wire:model, both reading (on open) and writing
     * (on save) without needing a separate event listener.
     */
    #[Modelable]
    public string $blocksData = '[]';

    /** @var list<SectionEntry> Raw blocks_data as a PHP array */
    public array $sections = [];

    /** Current autosave status label shown in the header */
    public string $saveStatus = '';

    /** Entry id passed from parent for context; not required for editor logic */
    public string $entryId = '';

    /** Whether the entry is currently published (affects autosave path) */
    public bool $isPublished = false;

    /**
     * Who else holds this document's edit lock, or null when we do (or when
     * no lock provider is installed). Set at mount; save() re-checks live.
     */
    public ?string $lockedBy = null;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * @param  string  $blocksData  Explicit JSON override (used in tests and direct embedding).
     *                              When empty, falls back to the #[Modelable] $this->blocksData
     *                              which Filament sets before mount() via wire:model hydration.
     */
    public function mount(string $blocksData = '', string $entryId = '', bool $isPublished = false): void
    {
        $this->entryId = $entryId;
        $this->isPublished = $isPublished;
        $source = $blocksData !== '' ? $blocksData : $this->blocksData;
        $decoded = json_decode($source, true) ?: [];
        $this->sections = $this->normalizeTokenOverridesForEditor($decoded);

        // Share the document lock with the visual builder when a provider
        // is installed (magna/pages binds one) — two editors, one lock.
        if ($entryId !== '' && app()->bound(GuardsDocumentEdits::class)) {
            $lock = app(GuardsDocumentEdits::class)
                ->acquire($entryId, auth()->user());
            $this->lockedBy = $lock['mine'] ? null : $lock['holderName'];
        }
    }

    /**
     * Convert stored {key: value} tokenOverrides maps to [{key, value}] arrays
     * that Livewire can bind to indexed inputs.
     *
     * @param  list<mixed>  $sections
     * @return list<SectionEntry>
     */
    private function normalizeTokenOverridesForEditor(array $sections): array
    {
        foreach ($sections as &$section) {
            if (! is_array($section)) {
                continue;
            }

            $settings = $section['settings'] ?? [];
            if (! is_array($settings)) {
                continue;
            }

            $overrides = $settings['tokenOverrides'] ?? [];
            if (is_array($overrides) && ! array_is_list($overrides)) {
                $normalized = [];
                foreach ($overrides as $k => $v) {
                    $normalized[] = [
                        'key' => is_string($k) ? $k : '',
                        'value' => is_string($v) ? $v : '',
                    ];
                }

                $section['settings']['tokenOverrides'] = $normalized;
            }
        }
        unset($section);

        return $sections;
    }

    // ── Sections ──────────────────────────────────────────────────────────────

    public function addSection(): void
    {
        $this->sections[] = [
            'id' => (string) Str::ulid(),
            'type' => 'section',
            'settings' => [
                'background' => ['type' => 'none', 'value' => '', 'overlay' => 0],
                'padding' => ['top' => 'lg', 'bottom' => 'lg'],
                'maxWidth' => '2xl',
                'anchor' => '',
                'visibility' => ['desktop' => true, 'tablet' => true, 'mobile' => true],
                'cssClass' => '',
                'tokenOverrides' => [],
            ],
            'columns' => [
                [
                    'id' => (string) Str::ulid(),
                    'span' => 12,
                    'settings' => ['verticalAlign' => 'top', 'padding' => ['x' => 'none', 'y' => 'none'], 'cssClass' => ''],
                    'blocks' => [],
                ],
            ],
        ];
    }

    public function removeSection(int $sectionIndex): void
    {
        if ($sectionIndex < 0 || ! isset($this->sections[$sectionIndex])) {
            return;
        }
        array_splice($this->sections, $sectionIndex, 1);
        $this->sections = array_values($this->sections);
    }

    public function moveSectionUp(int $index): void
    {
        if ($index <= 0) {
            return;
        }
        [$this->sections[$index - 1], $this->sections[$index]] = [$this->sections[$index], $this->sections[$index - 1]];
    }

    public function moveSectionDown(int $index): void
    {
        if ($index < 0 || $index >= count($this->sections) - 1) {
            return;
        }
        [$this->sections[$index], $this->sections[$index + 1]] = [$this->sections[$index + 1], $this->sections[$index]];
    }

    /** @var list<string> Permitted top-level settings keys for updateSectionSettings */
    private const SECTION_SETTINGS_KEYS = [
        'background', 'padding', 'maxWidth', 'anchor', 'visibility', 'cssClass',
    ];

    public function updateSectionSettings(int $sectionIndex, string $key, mixed $value): void
    {
        if (! isset($this->sections[$sectionIndex])) {
            return;
        }
        if (! in_array($key, self::SECTION_SETTINGS_KEYS, true)) {
            return;
        }
        data_set($this->sections[$sectionIndex], "settings.{$key}", $value);
    }

    public function addTokenOverride(int $sectionIndex): void
    {
        if (! isset($this->sections[$sectionIndex])) {
            return;
        }
        $this->sections[$sectionIndex]['settings']['tokenOverrides'][] = ['key' => '', 'value' => ''];
    }

    public function removeTokenOverride(int $sectionIndex, int $overrideIndex): void
    {
        if (! isset($this->sections[$sectionIndex]['settings']['tokenOverrides'][$overrideIndex])) {
            return;
        }
        array_splice($this->sections[$sectionIndex]['settings']['tokenOverrides'], $overrideIndex, 1);
        $this->sections[$sectionIndex]['settings']['tokenOverrides'] = array_values(
            $this->sections[$sectionIndex]['settings']['tokenOverrides']
        );
    }

    // ── Columns ───────────────────────────────────────────────────────────────

    /**
     * Apply a column layout preset.  $spans is an array of integer column spans
     * that must sum to 12, e.g. [6, 6] or [4, 4, 4].
     *
     * @param  list<int>  $spans
     */
    public function applyColumnLayout(int $sectionIndex, array $spans): void
    {
        if (! isset($this->sections[$sectionIndex])) {
            return;
        }
        if ($spans === [] || array_sum($spans) !== 12 || min($spans) < 1) {
            return;
        }

        $oldColumns = $this->sections[$sectionIndex]['columns'] ?? [];
        $newCount = count($spans);

        $newColumns = [];
        foreach ($spans as $i => $span) {
            // Each new column keeps the blocks from the matching old column.
            // If the new layout has fewer columns than the old one, the last new
            // column absorbs blocks from all surplus old columns.
            $colBlocks = $oldColumns[$i]['blocks'] ?? [];
            if ($i === $newCount - 1 && count($oldColumns) > $newCount) {
                for ($j = $newCount; $j < count($oldColumns); $j++) {
                    $colBlocks = array_merge($colBlocks, $oldColumns[$j]['blocks'] ?? []);
                }
            }
            $newColumns[] = [
                'id' => $oldColumns[$i]['id'] ?? (string) Str::ulid(),
                'span' => $span,
                'settings' => $oldColumns[$i]['settings'] ?? ['verticalAlign' => 'top', 'padding' => ['x' => 'none', 'y' => 'none'], 'cssClass' => ''],
                'blocks' => $colBlocks,
            ];
        }

        $this->sections[$sectionIndex]['columns'] = $newColumns;
    }

    // ── Blocks ────────────────────────────────────────────────────────────────

    public function addBlock(int $sectionIndex, int $columnIndex, string $blockHandle): void
    {
        if (! isset($this->sections[$sectionIndex]['columns'][$columnIndex])) {
            return;
        }

        /** @var BlockRegistry $registry */
        $registry = app(BlockRegistry::class);
        $definition = $registry->get($blockHandle);
        if ($definition === null) {
            return;
        }

        $defaultData = [];
        foreach ($definition->fields as $field) {
            if ($field->default !== null) {
                $defaultData[$field->handle] = $field->default;
            }
        }

        $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][] = [
            'id' => (string) Str::ulid(),
            'block' => $blockHandle,
            'settings' => [
                'spacing' => ['top' => 'none', 'bottom' => 'none'],
                'visibility' => ['desktop' => true, 'tablet' => true, 'mobile' => true],
                'cssClass' => '',
            ],
            'data' => $defaultData,
        ];
    }

    public function removeBlock(int $sectionIndex, int $columnIndex, int $blockIndex): void
    {
        if (! isset($this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex])) {
            return;
        }
        array_splice($this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'], $blockIndex, 1);
        $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'] = array_values(
            $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks']
        );
    }

    public function duplicateBlock(int $sectionIndex, int $columnIndex, int $blockIndex): void
    {
        if (! isset($this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex])) {
            return;
        }
        $source = $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex];
        $copy = $source;
        $copy['id'] = (string) Str::ulid();

        array_splice(
            $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'],
            $blockIndex + 1,
            0,
            [$copy],
        );
    }

    public function moveBlockUp(int $sectionIndex, int $columnIndex, int $blockIndex): void
    {
        if ($blockIndex <= 0) {
            return;
        }
        if (! isset($this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex])) {
            return;
        }
        $blocks = &$this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'];
        [$blocks[$blockIndex - 1], $blocks[$blockIndex]] = [$blocks[$blockIndex], $blocks[$blockIndex - 1]];
    }

    public function moveBlockDown(int $sectionIndex, int $columnIndex, int $blockIndex): void
    {
        if (! isset($this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex])) {
            return;
        }
        $blocks = &$this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'];
        if ($blockIndex >= count($blocks) - 1) {
            return;
        }
        [$blocks[$blockIndex], $blocks[$blockIndex + 1]] = [$blocks[$blockIndex + 1], $blocks[$blockIndex]];
    }

    public function updateBlockData(int $sectionIndex, int $columnIndex, int $blockIndex, string $fieldHandle, mixed $value): void
    {
        if (! isset($this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex])) {
            return;
        }
        $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex]['data'][$fieldHandle] = $value;
    }

    // ── Repeater fields ───────────────────────────────────────────────────────

    public function addRepeaterItem(int $sectionIndex, int $columnIndex, int $blockIndex, string $fieldHandle): void
    {
        $field = $this->repeaterField($sectionIndex, $columnIndex, $blockIndex, $fieldHandle);
        if ($field === null) {
            return;
        }

        $items = $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex]['data'][$fieldHandle] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            $items = [];
        }

        if (count($items) >= BlockField::MAX_REPEATER_ITEMS) {
            return;
        }

        $item = [];
        foreach ($field->fields as $itemField) {
            $item[$itemField->handle] = $itemField->default
                ?? ($itemField->type === 'boolean' ? false : '');
        }

        $items[] = $item;
        $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex]['data'][$fieldHandle] = $items;
    }

    public function removeRepeaterItem(int $sectionIndex, int $columnIndex, int $blockIndex, string $fieldHandle, int $itemIndex): void
    {
        if ($this->repeaterField($sectionIndex, $columnIndex, $blockIndex, $fieldHandle) === null) {
            return;
        }

        $items = $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex]['data'][$fieldHandle] ?? [];
        if (! is_array($items) || ! isset($items[$itemIndex])) {
            return;
        }

        array_splice($items, $itemIndex, 1);
        $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex]['data'][$fieldHandle] = array_values($items);
    }

    /**
     * The block's repeater field definition for a handle, or null when the
     * block/field is unknown or not a repeater (guards Livewire calls).
     */
    private function repeaterField(int $sectionIndex, int $columnIndex, int $blockIndex, string $fieldHandle): ?BlockField
    {
        $block = $this->sections[$sectionIndex]['columns'][$columnIndex]['blocks'][$blockIndex] ?? null;
        if (! is_array($block)) {
            return null;
        }

        $definition = app(BlockRegistry::class)->get($block['block']);
        if ($definition === null) {
            return null;
        }

        $field = $definition->field($fieldHandle);

        return $field !== null && $field->type === 'repeater' ? $field : null;
    }

    // ── Media fields ──────────────────────────────────────────────────────────

    /**
     * Receive a selection from the global media-picker modal
     * (<livewire:magna-media-picker />). The blade dispatches
     * magna:open-media-picker with target "block-field:{si}:{ci}:{bi}:{handle}";
     * the id echoed back here is the Media ULID block views resolve
     * (see resources/views/blocks/image.blade.php — media is stored by id,
     * never by disk path, so documents stay portable).
     */
    #[On('magna:media-selected')]
    public function onMediaSelected(string $path, string $url, string $disk, string $target, string $id = ''): void
    {
        if (! str_starts_with($target, 'block-field:')) {
            return; // a different picker consumer's selection
        }

        $parts = explode(':', $target);
        if (count($parts) !== 5 || $id === '') {
            return;
        }

        [, $si, $ci, $bi, $fieldHandle] = $parts;
        if (! ctype_digit($si) || ! ctype_digit($ci) || ! ctype_digit($bi) || $fieldHandle === '') {
            return;
        }

        $this->updateBlockData((int) $si, (int) $ci, (int) $bi, $fieldHandle, $id);
    }

    public function clearMediaField(int $sectionIndex, int $columnIndex, int $blockIndex, string $fieldHandle): void
    {
        $this->updateBlockData($sectionIndex, $columnIndex, $blockIndex, $fieldHandle, null);
    }

    /**
     * Thumbnail URL for a stored media value (Media ULID), or null when the
     * value is empty, unknown, or not an image. Used by the editor blade for
     * the field preview.
     */
    public function mediaThumbUrl(mixed $mediaId): ?string
    {
        $media = $this->findMedia($mediaId);
        if ($media === null || ! $media->isImage()) {
            return null;
        }

        return app(MediaUrlResolver::class)->publicUrl($media, 'thumb');
    }

    /** Human label for a stored media value, or null when unresolvable. */
    public function mediaLabel(mixed $mediaId): ?string
    {
        $media = $this->findMedia($mediaId);

        return $media !== null
            ? (filled($media->title) ? (string) $media->title : $media->original_filename)
            : null;
    }

    private function findMedia(mixed $mediaId): ?Media
    {
        if (! is_string($mediaId) || $mediaId === '') {
            return null;
        }

        return Media::query()->find($mediaId);
    }

    // ── Serialise & save ──────────────────────────────────────────────────────

    /**
     * Serialise the current editor state to JSON.
     * Converts tokenOverrides from editor [{key, value}] format to stored {key: value} map.
     */
    public function serialise(): string
    {
        $data = $this->sections;
        foreach ($data as &$section) {
            $overrides = $section['settings']['tokenOverrides'] ?? [];
            if (is_array($overrides) && array_is_list($overrides)) {
                $map = [];
                foreach ($overrides as $override) {
                    $k = trim((string) ($override['key'] ?? ''));
                    $v = trim((string) ($override['value'] ?? ''));
                    if ($k !== '' && $v !== '') {
                        $map[$k] = $v;
                    }
                }
                $section['settings']['tokenOverrides'] = $map;
            }
        }
        unset($section);

        try {
            return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Block editor state could not be serialised to JSON: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Called by the editor's "Save draft" or autosave mechanism.
     * Updates the #[Modelable] blocksData property (propagates to parent form)
     * and dispatches blockEditorUpdated for any additional listeners.
     */
    public function save(): void
    {
        // Re-checked live, not from the mount-time flag: the lock may have
        // been taken over (or gone stale and been reacquired) while this
        // screen sat open. Refusing here keeps the stale serialisation out
        // of the modelable property, so a form save cannot overwrite what
        // the lock holder is editing.
        if ($this->entryId !== '' && app()->bound(GuardsDocumentEdits::class)) {
            $guard = app(GuardsDocumentEdits::class);
            if (! $guard->holds($this->entryId, auth()->user())) {
                $lock = $guard->acquire($this->entryId, auth()->user());
                if (! $lock['mine']) {
                    $this->lockedBy = $lock['holderName'];
                    $this->saveStatus = 'Not saved — '.($lock['holderName'] ?? 'another editor').' is editing this page.';

                    return;
                }
                $this->lockedBy = null;
            }
        }

        $this->saveStatus = 'Saving…';
        $this->blocksData = $this->serialise();
        $this->dispatch('blockEditorUpdated', blocksData: $this->blocksData);
        $this->saveStatus = 'Saved '.now()->format('H:i:s');
    }

    // ── Available blocks for the "Add block" modal ────────────────────────────

    /**
     * @return array<string, list<array{handle: string, label: string, icon: string}>>
     */
    public function availableBlocks(): array
    {
        /** @var BlockRegistry $registry */
        $registry = app(BlockRegistry::class);

        $grouped = [];
        foreach ($registry->grouped() as $category => $definitions) {
            foreach ($definitions as $def) {
                $grouped[$category][] = [
                    'handle' => $def->handle,
                    'label' => $def->label,
                    'icon' => $def->icon,
                ];
            }
        }

        return $grouped;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('magna::block-editor.editor', [
            'sections' => $this->sections,
            'availableBlocks' => $this->availableBlocks(),
            'saveStatus' => $this->saveStatus,
        ]);
    }
}
