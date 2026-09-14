<?php

declare(strict_types=1);

namespace Magna\Admin\Resources\Entry;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Magna\Admin\Resources\EntryResource;
use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;

class ListEntries extends ListRecords
{
    protected static string $resource = EntryResource::class;

    #[Url(as: 'type')]
    public string $type = '';

    public function getTitle(): string|Htmlable
    {
        /** @var SchemaRegistry $registry */
        $registry = app(SchemaRegistry::class);
        $contentType = $registry->get($this->type);

        return $contentType ? $contentType->displayName : 'Content';
    }

    /** @return Builder<Entry>|null */
    protected function getTableQuery(): ?Builder
    {
        if ($this->type === '') {
            return Entry::query()->whereRaw('1 = 0');
        }

        return Entry::type($this->type);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->url(EntryResource::getUrl('create', ['type' => $this->type])),
        ];
    }
}
