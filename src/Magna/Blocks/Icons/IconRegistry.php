<?php

declare(strict_types=1);

namespace Magna\Blocks\Icons;

/**
 * The icons a site may draw, by name.
 *
 * A server-owned allowlist, deliberately the same shape as the block
 * registry and the style descriptor table: the vocabulary lives here, the
 * builder receives it, and a document stores only a NAME. That is the
 * whole security argument — an icon field holds `core:star`, never
 * markup, so no document can ever carry SVG into a page. An unknown name
 * draws nothing rather than guessing.
 *
 * Icons are geometry only, stroked in `currentColor`, so one icon works
 * on any background, in either colour scheme, at any size, without a
 * second copy of it existing anywhere.
 *
 * Plugins register their own through the same door, and pay the same
 * inspection at it: see register().
 */
final class IconRegistry
{
    /**
     * SVG elements an icon may be built from.
     *
     * Geometry, and nothing that can fetch, script, or navigate. `use` and
     * `image` are absent because both take a reference; `foreignObject`
     * because it opens the door to arbitrary HTML; `style` because it
     * escapes the icon.
     */
    private const ALLOWED_ELEMENTS = [
        'path', 'circle', 'ellipse', 'rect', 'line',
        'polyline', 'polygon', 'g',
    ];

    /** @var array<string, string> name => inner SVG markup */
    private array $icons = [];

    /**
     * Add an icon, or refuse it.
     *
     * Refusal is silent by design: a plugin shipping one malformed icon
     * should lose that icon, not take the site down at boot. What it must
     * never do is get markup of its own choosing onto every page that
     * draws an icon, which is what the inspection below prevents.
     */
    public function register(string $name, string $body): void
    {
        if (! self::isValidName($name) || ! self::isSafeBody($body)) {
            return;
        }

        $this->icons[$name] = trim($body);
    }

    /**
     * Load a set file: {"set": "core", "icons": {"star": "<path …/>"}}.
     *
     * Names are namespaced by the set, so two sets may both offer a
     * "star" and neither silently wins.
     */
    public function loadFromFile(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return;
        }

        $set = $decoded['set'] ?? null;
        $icons = $decoded['icons'] ?? null;
        if (! is_string($set) || ! is_array($icons)) {
            return;
        }

        foreach ($icons as $name => $body) {
            if (is_string($name) && is_string($body)) {
                $this->register($set.':'.$name, $body);
            }
        }
    }

    /** Load every set file in a directory. */
    public function loadFromDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $this->loadFromFile($file);
        }
    }

    public function has(string $name): bool
    {
        return isset($this->icons[$name]);
    }

    /** The icon's inner markup, or null when nothing is registered. */
    public function body(string $name): ?string
    {
        return $this->icons[$name] ?? null;
    }

    /**
     * A complete <svg> for this icon, or null.
     *
     * `aria-hidden` by default because an icon beside a label is
     * decoration, and announcing it twice is worse than not announcing it.
     * A caller giving the icon its own meaning passes a label.
     */
    public function svg(string $name, ?string $label = null, ?string $class = null, ?int $size = null): ?string
    {
        $body = $this->body($name);
        if ($body === null) {
            return null;
        }

        $attributes = [
            'xmlns' => 'http://www.w3.org/2000/svg',
            'viewBox' => '0 0 24 24',
            'fill' => 'none',
            'stroke' => 'currentColor',
            'stroke-width' => '1.5',
            'stroke-linecap' => 'round',
            'stroke-linejoin' => 'round',
        ];

        if ($class !== null && $class !== '') {
            $attributes['class'] = $class;
        }

        /*
         * An intrinsic size, in pixels, when the caller gives one.
         *
         * Core block views ship no stylesheet of their own, so an <svg>
         * with only a viewBox would be laid out at the SVG default of
         * 300×150 — an icon block that looks broken until a theme happens
         * to style it. Width and height attributes are the lowest-priority
         * way to say how big it is, so any CSS still wins.
         */
        if ($size !== null && $size > 0) {
            $attributes['width'] = (string) $size;
            $attributes['height'] = (string) $size;
        }

        if ($label !== null && $label !== '') {
            $attributes['role'] = 'img';
            $attributes['aria-label'] = $label;
        } else {
            $attributes['aria-hidden'] = 'true';
            $attributes['focusable'] = 'false';
        }

        $rendered = '';
        foreach ($attributes as $attribute => $value) {
            $rendered .= ' '.$attribute.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
        }

        return '<svg'.$rendered.'>'.$body.'</svg>';
    }

    /**
     * Every icon, as name => inner markup — what the builder needs to draw
     * a picker without a round trip per icon.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        ksort($this->icons);

        return $this->icons;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function count(): int
    {
        return count($this->icons);
    }

    /** `set:name`, both lowercase — the shape the icon field validates. */
    private static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*$/', $name) === 1;
    }

    /**
     * Geometry only.
     *
     * Every tag must be an allowed element, and nothing may carry an event
     * handler or a reference. Checked here rather than trusted from the
     * file, because "we ship the files" stops being true the moment a
     * plugin registers an icon.
     */
    private static function isSafeBody(string $body): bool
    {
        if ($body === '' || preg_match('/<[!?]/', $body) === 1) {
            return false;
        }

        // No handlers, no references, no data or script URLs.
        if (preg_match('/\son[a-z]+\s*=/i', $body) === 1) {
            return false;
        }
        if (preg_match('/(href|src|xlink:href|style)\s*=/i', $body) === 1) {
            return false;
        }
        if (stripos($body, 'javascript:') !== false || stripos($body, 'data:') !== false) {
            return false;
        }

        preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9:-]*)/', $body, $matches);
        foreach ($matches[1] as $element) {
            if (! in_array(strtolower($element), self::ALLOWED_ELEMENTS, true)) {
                return false;
            }
        }

        return $matches[1] !== [];
    }
}
