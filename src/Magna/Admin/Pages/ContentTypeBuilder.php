<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Magna\Content\ContentType;
use Magna\Content\ContentTypeApplier;
use Magna\Content\DiffResult;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\ContentTypeRecord;
use Magna\Content\SchemaDiffer;

class ContentTypeBuilder extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Type Builder';

    protected static ?string $title = 'Content Type Builder';

    protected static ?int $navigationSort = 50;

    protected string $view = 'magna::admin.content-type-builder';

    // ── State ─────────────────────────────────────────────────────────────────

    #[Url(as: 'edit')]
    public ?string $editHandle = null;

    /** @var array<string, mixed> */
    public array $typeData = [
        'handle' => '',
        'displayName' => '',
        'localizable' => false,
        'draftable' => true,
        'fields' => [],
    ];

    /** Pending diff result awaiting user confirmation */
    public ?DiffResult $pendingDiff = null;

    public bool $showDiffConfirm = false;

    public bool $allowDestructive = false;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * The registered content types the list at the top of the page renders.
     * Fetched here rather than queried inside the Blade view — views render,
     * pages fetch (enforced by the no-queries-in-views architecture rule).
     *
     * @return Collection<int, ContentTypeRecord>
     */
    public function contentTypeRecords(): Collection
    {
        return ContentTypeRecord::query()->orderBy('display_name')->get();
    }

    public function mount(): void
    {
        if ($this->editHandle !== null) {
            $record = ContentTypeRecord::query()
                ->where('handle', $this->editHandle)
                ->first();

            if ($record instanceof ContentTypeRecord && is_array($record->schema)) {
                $this->typeData = $record->schema;
            }
        }
    }

    // ── Schema / form ─────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Type definition')
                ->columns(2)
                ->schema([
                    TextInput::make('typeData.handle')
                        ->label('Handle')
                        ->helperText('Lowercase, underscores only. Cannot be changed once created.')
                        ->required()
                        ->readOnly($this->editHandle !== null)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->maxLength(64),

                    TextInput::make('typeData.displayName')
                        ->label('Display name')
                        ->required()
                        ->maxLength(128),

                    Toggle::make('typeData.localizable')
                        ->label('Localizable')
                        ->helperText('Creates separate entries per locale.'),

                    Toggle::make('typeData.draftable')
                        ->label('Draftable')
                        ->helperText('Allows draft/publish workflow.')
                        ->default(true),
                ]),

            Section::make('Fields')
                ->schema([
                    Repeater::make('typeData.fields')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('handle')
                                ->label('Handle')
                                ->required()
                                ->regex('/^[a-z][a-z0-9_]*$/')
                                ->maxLength(64)
                                ->columnSpan(1),

                            Select::make('type')
                                ->label('Type')
                                ->options($this->getFieldTypeOptions())
                                ->required()
                                ->reactive()
                                ->columnSpan(1),

                            Toggle::make('required')
                                ->label('Required')
                                ->columnSpan(1),

                            Toggle::make('localizable')
                                ->label('Localizable')
                                ->columnSpan(1),
                        ])
                        ->columns(2)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['handle'] ?? null)
                        ->addActionLabel('Add field'),
                ]),
        ]);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply')
                ->label('Apply changes')
                ->icon('heroicon-m-check-circle')
                ->color('primary')
                ->action('previewDiff'),

            Action::make('list_types')
                ->label('All types')
                ->icon('heroicon-m-list-bullet')
                ->color('gray')
                ->url(fn (): string => route('filament.magna.pages.content-type-builder')),
        ];
    }

    // ── Diff preview and apply ────────────────────────────────────────────────

    public function previewDiff(): void
    {
        $this->validate([
            'typeData.handle' => ['required', 'regex:/^[a-z][a-z0-9_]*$/'],
            'typeData.displayName' => ['required', 'string'],
        ]);

        try {
            $schema = $this->buildSchemaArray();
            $type = ContentType::fromArray($schema, app(FieldTypeRegistry::class));

            /** @var SchemaDiffer $differ */
            $differ = app(SchemaDiffer::class);

            $this->pendingDiff = $differ->diff($type);
            $this->showDiffConfirm = true;
        } catch (SchemaException $e) {
            Notification::make()
                ->title('Schema error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applyDiff(): void
    {
        if ($this->pendingDiff === null) {
            return;
        }

        try {
            $schema = $this->buildSchemaArray();
            $type = ContentType::fromArray($schema, app(FieldTypeRegistry::class));

            /** @var ContentTypeApplier $applier */
            $applier = app(ContentTypeApplier::class);
            $applier->apply($type, $schema, $this->pendingDiff, $this->allowDestructive);

            $this->pendingDiff = null;
            $this->showDiffConfirm = false;
            $this->allowDestructive = false;

            Notification::make()
                ->title('Content type saved')
                ->body('"'.$type->displayName.'" has been saved and the database schema updated.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error applying schema')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function cancelDiff(): void
    {
        $this->pendingDiff = null;
        $this->showDiffConfirm = false;
        $this->allowDestructive = false;
    }

    // ── Helper: build the schema array from Livewire state ────────────────────

    /** @return array<string, mixed> */
    private function buildSchemaArray(): array
    {
        $handle = $this->typeData['handle'] ?? '';
        $displayName = $this->typeData['displayName'] ?? '';

        if (! is_string($handle) || ! is_string($displayName)) {
            throw new SchemaException('Handle and displayName must be strings.');
        }

        return [
            'handle' => $handle,
            'displayName' => $displayName,
            'localizable' => (bool) ($this->typeData['localizable'] ?? false),
            'draftable' => (bool) ($this->typeData['draftable'] ?? true),
            'fields' => $this->typeData['fields'] ?? [],
        ];
    }

    /** @return array<string, string> */
    private function getFieldTypeOptions(): array
    {
        /** @var FieldTypeRegistry $registry */
        $registry = app(FieldTypeRegistry::class);
        $options = [];

        foreach ($registry->all() as $name => $class) {
            $options[$name] = ucwords(str_replace('_', ' ', $name));
        }

        ksort($options);

        return $options;
    }

    // ── Auth check ────────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }
}
