<?php

declare(strict_types=1);

namespace Magna\Media;

use Illuminate\Support\Facades\Auth;
use Magna\Contracts\MediaSource;
use Magna\Contracts\RegistersMediaSources;
use Magna\Plugins\PluginManager;
use Magna\Users\User;
use Throwable;

/**
 * The media the installation holds that does not live in the media table.
 *
 * The library is presented to operators as the one screen that accounts for
 * every file on the site, and for a long time that was not true: a plugin
 * storing uploads on its own disk — empanelment documents, message
 * attachments — appeared nowhere, and nothing told the operator the list was
 * partial. Silence read as "there is nothing", which is the worst way for a
 * data inventory to be wrong.
 *
 * What is collected here is a description of those files, never the files.
 * Sources answer with names, sizes and a link back to the plugin's own screen;
 * a confidential source gives no thumbnail and no URL to the bytes. Central
 * visibility, not central access — the plugin holding a file stays the only
 * thing that decides who may open it.
 */
class MediaSourceRegistry
{
    public function __construct(private readonly PluginManager $plugins) {}

    /**
     * Every source the current user is allowed to know about.
     *
     * @return list<MediaSource>
     */
    public function visible(): array
    {
        $user = Auth::user();

        return array_values(array_filter(
            $this->all(),
            fn (MediaSource $source): bool => $this->permits($source, $user instanceof User ? $user : null),
        ));
    }

    /**
     * One source by key, or null when it does not exist or is not permitted.
     *
     * Resolved through visible() on purpose: a key arriving from the query
     * string must not reach a source the user could not have been shown.
     */
    public function find(string $key): ?MediaSource
    {
        foreach ($this->visible() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Every declared source, permissions not yet applied.
     *
     * One plugin's failure costs exactly itself. A source that throws while
     * being collected must not take the media library — the screen an operator
     * reaches for when something is already wrong — down with it.
     *
     * @return list<MediaSource>
     */
    public function all(): array
    {
        $sources = [];
        $seen = [];

        foreach ($this->plugins->getEnabled() as $plugin) {
            if (! $plugin instanceof RegistersMediaSources) {
                continue;
            }

            try {
                foreach ($plugin->mediaSources() as $source) {
                    $key = $source->key();

                    // Two plugins claiming one key would make the tab
                    // ambiguous and the deep link wrong. First registered wins,
                    // deterministically, rather than whichever booted last.
                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $sources[] = $source;
                }
            } catch (Throwable) {
                // This plugin lists nothing; every other source still does.
            }
        }

        return $sources;
    }

    /**
     * Through the Gate, not the user's raw grants: an unregistered permission
     * key must be refused rather than quietly matched, so a plugin cannot open
     * a confidential source by naming a permission that does not exist.
     */
    private function permits(MediaSource $source, ?User $user): bool
    {
        $permission = $source->permission();

        if ($permission === null) {
            return true;
        }

        return $user?->can($permission) ?? false;
    }
}
