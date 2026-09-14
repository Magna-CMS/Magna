<?php

declare(strict_types=1);

namespace Magna\Admin\Resources\Media;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Magna\Admin\Resources\MediaResource;
use Magna\Media\Media;

class TrashMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected static ?string $title = 'Recycle Bin';

    public function getBreadcrumb(): string
    {
        return 'Recycle Bin';
    }

    /** @return Builder<Media> */
    protected function getTableQuery(): Builder
    {
        return Media::onlyTrashed();
    }

    /**
     * Strip TrashedFilter from the inherited table definition.
     *
     * TrashedFilter's blank (default) state applies ->withoutTrashed() which
     * adds WHERE deleted_at IS NULL, conflicting with the onlyTrashed() base
     * query and producing zero rows. This page is already scoped to trash.
     */
    public function table(Table $table): Table
    {
        $table = MediaResource::table($table);

        return $table->filters(
            array_values(array_filter(
                $table->getFilters(),
                fn ($f) => $f->getName() !== 'trashed',
            )),
        );
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
