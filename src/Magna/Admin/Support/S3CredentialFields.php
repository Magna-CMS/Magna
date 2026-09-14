<?php

declare(strict_types=1);

namespace Magna\Admin\Support;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * The S3-compatible credential field group (key/secret/bucket/region/URL)
 * shown whenever an admin picks an "s3"/"s3-like" storage driver. Reused by
 * BackupSettingsPage's "Destination" section and SettingsPage's "Storage"
 * tab, which previously each defined this identically.
 *
 * $visible, if given, gates all five fields the same way both call sites
 * already did: visible only when the paired disk-driver select is s3/s3-like.
 *
 * $prefix produces the same group under prefixed state names
 * (secondary_s3_key, …) for a second destination — BackupSettingsPage's
 * offsite copy previously re-declared all five fields by hand.
 */
class S3CredentialFields
{
    /**
     * @param  (\Closure(callable $get): bool)|null  $visible
     * @return list<Component>
     */
    public static function make(?\Closure $visible = null, string $prefix = ''): array
    {
        $fields = [
            TextInput::make($prefix.'s3_key')
                ->label('S3 access key')
                ->maxLength(255)
                ->nullable(),

            TextInput::make($prefix.'s3_secret')
                ->label('S3 secret key')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->helperText('Leave blank to keep the existing secret unchanged.'),

            TextInput::make($prefix.'s3_bucket')
                ->label('S3 bucket')
                ->maxLength(255)
                ->nullable(),

            TextInput::make($prefix.'s3_region')
                ->label('S3 region')
                ->maxLength(100)
                ->nullable()
                ->placeholder('us-east-1'),

            TextInput::make($prefix.'s3_url')
                ->label('S3 endpoint URL')
                ->url()
                ->maxLength(500)
                ->nullable()
                ->helperText('Leave blank for AWS S3. Set for S3-compatible services (R2, MinIO).'),
        ];

        if ($visible === null) {
            return $fields;
        }

        foreach ($fields as $field) {
            $field->visible($visible);
        }

        return $fields;
    }
}
