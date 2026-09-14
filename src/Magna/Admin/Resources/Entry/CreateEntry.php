<?php

declare(strict_types=1);

namespace Magna\Admin\Resources\Entry;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;
use Magna\Admin\Resources\EntryResource;
use Magna\Content\EntryManager;
use Magna\Content\SchemaRegistry;

class CreateEntry extends CreateRecord
{
    protected static string $resource = EntryResource::class;

    #[Url(as: 'type')]
    public string $type = '';

    public function getTitle(): string|Htmlable
    {
        return 'Create '.(app(SchemaRegistry::class)->get($this->type)->displayName ?? 'Entry');
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var EntryManager $manager */
        $manager = app(EntryManager::class);
        $authorId = auth()->id();

        return $manager->create(
            typeHandle: $this->type,
            data: $data,
            authorId: $authorId !== null ? (string) $authorId : null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return EntryResource::getUrl('index', ['type' => $this->type]);
    }
}
