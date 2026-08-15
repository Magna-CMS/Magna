<?php

declare(strict_types=1);

namespace Magna\Admin\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;

/**
 * Fallback avatar for users with no photo, drawn locally as a data: URI.
 *
 * Filament's stock UiAvatarsProvider builds the initials image by pointing an
 * <img> at https://ui-avatars.com — a third-party origin the panel's CSP does
 * not allow, so the fallback rendered as a broken image. Allowlisting that host
 * would fix the icon but ship every admin's real name to an outside service on
 * every page load, and leave the panel dependent on outbound internet for a
 * two-letter graphic. Rendering the same thing as an inline SVG needs neither.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initials = $this->initialsFor(Filament::getNameForDefaultAvatar($record));

        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
            <rect width="128" height="128" fill="{$this->escape($background)}"/>
            <text x="50%" y="50%" dy="0.35em" text-anchor="middle" fill="#FFFFFF"
            font-family="ui-sans-serif, system-ui, sans-serif" font-size="52" font-weight="500">{$this->escape($initials)}</text>
            </svg>
            SVG;

        // Base64 rather than percent-encoding: the initials are user-supplied,
        // and base64 cannot be broken out of by a stray quote or hash.
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** First letter of the first two words, matching Filament's own initials. */
    private function initialsFor(string $name): string
    {
        $segments = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(
            collect($segments)
                ->take(2)
                ->map(fn (string $segment): string => mb_substr($segment, 0, 1))
                ->join('')
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
