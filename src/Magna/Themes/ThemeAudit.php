<?php

declare(strict_types=1);

namespace Magna\Themes;

/**
 * The restricted-view audit, for a theme package before it is installed.
 *
 * A theme is Blade views, and Blade is PHP: an uploaded theme is code the
 * site will run. The rules that decide what a theme view may do (no raw
 * PHP, no forbidden calls, no queries, no unescaped output outside the
 * layout contract) live with the renderer that enforces them — the page
 * builder — which implements this and binds it in the container. Core asks
 * before placing an uploaded package and refuses one with errors; without
 * an implementation it refuses uploads altogether rather than install code
 * nobody checked.
 */
interface ThemeAudit
{
    /**
     * @return list<string> one line per error, "file: message"; [] when the package passes
     */
    public function errors(string $themeDirectory): array;
}
