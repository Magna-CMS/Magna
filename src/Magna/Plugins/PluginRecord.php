<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $display_name
 * @property string $version
 * @property bool $enabled
 * @property bool $requires_license Installed through the licensed download path.
 * @property string $base_path
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property array<string, mixed> $manifest
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginRecord extends Model
{
    use HasUlids;

    protected $table = 'plugins';

    protected $fillable = [
        'name',
        'display_name',
        'version',
        'enabled',
        'requires_license',
        'base_path',
        'enabled_at',
        'disabled_at',
        'manifest',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'requires_license' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'manifest' => 'json',
        ];
    }
}
