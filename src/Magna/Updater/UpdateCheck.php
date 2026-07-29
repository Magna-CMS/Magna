<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type 'core' | 'plugin'
 * @property string|null $slug
 * @property string $current_version
 * @property string|null $latest_version
 * @property string|null $changelog_url
 * @property string|null $download_url
 * @property string|null $download_sha256
 * @property string|null $download_sha256_signature
 * @property bool $update_available
 * @property bool $license_required
 * @property Carbon $checked_at
 */
class UpdateCheck extends Model
{
    protected $table = 'update_checks';

    protected $fillable = [
        'type',
        'slug',
        'current_version',
        'latest_version',
        'changelog_url',
        'download_url',
        'download_sha256',
        'download_sha256_signature',
        'update_available',
        // A newer version exists that this site's licence does not cover.
        // Mutually exclusive with update_available — see the /updates
        // contract in Magna\Updater\UpdateEntry.
        'license_required',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'update_available' => 'boolean',
            'license_required' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public static function core(): ?self
    {
        return static::query()->where('type', 'core')->first();
    }

    /** @return Collection<int, static> */
    public static function pluginsWithUpdates(): Collection
    {
        return static::query()
            ->where('type', 'plugin')
            ->where('update_available', true)
            ->get();
    }

    public static function totalAvailable(): int
    {
        return static::query()->where('update_available', true)->count();
    }
}
