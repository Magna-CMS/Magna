{{--
    Block: features — the site's repeating marketing pattern.

    One block covers every one of them: the "why" cards, the use-case
    chips, the feature rows with status pills, the numbered steps, the
    architecture layers and every tick list. Which one you get is decided
    by the SECTION's CSS class (why, cases, plugins, feat-rows, …) and the
    block's own layout field — both editable from the builder's inspector,
    neither invented here.

    Over the core view this adds three optional item keys, all ignored by
    any other theme:

      status  a pill on the right of a feature row ("Available")
      tone    what colours that pill (avail | dev | plan)
      tag     a small label beside the title ("Opt-in", "API-first")

    `icon` keeps its core meaning — a short string — and additionally
    resolves against the sprite the layout defines when it names one of
    the icons below. A name nobody knows prints as text, which is what
    keeps an emoji working.
--}}
@php
    // Repeater-era data arrives as an array; the json field it is stored in
    // arrives as a string. Both are real states of live content.
    $raw = $block['data']['items'] ?? [];
    $items = is_array($raw) ? $raw : (json_decode(is_string($raw) ? $raw : '[]', true) ?: []);

    $layout = $block['data']['layout'] ?? 'icon-grid';
    $layout = in_array($layout, ['icon-grid', 'horizontal-list'], true) ? $layout : 'icon-grid';

    // The sprite's vocabulary, mirrored here so a value from a document can
    // never put an arbitrary fragment identifier into the markup.
    $sprite = [
        'check', 'check-circle', 'code', 'shield', 'bolt', 'modules', 'pencil',
        'screen', 'terminal', 'type', 'layout', 'layers', 'target', 'clock',
        'building', 'list', 'devices', 'chart', 'users', 'cog', 'sparkle',
        'arrow-right', 'arrow-up', 'close',
    ];

    $tones = ['avail', 'dev', 'plan'];
@endphp
<div class="magna-block magna-block--features magna-features magna-features--{{ $layout }}">
    @foreach($items as $item)
        @php
            $icon = trim((string) ($item['icon'] ?? ''));
            $isSprite = in_array($icon, $sprite, true);
            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $status = trim((string) ($item['status'] ?? ''));
            $tone = (string) ($item['tone'] ?? '');
            $tone = in_array($tone, $tones, true) ? $tone : 'plan';
            $tag = trim((string) ($item['tag'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
        @endphp
        <div class="magna-features__item">
            @if($icon !== '')
                <span class="magna-features__icon" aria-hidden="true">
                    @if($isSprite)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><use href="#i-{{ $icon }}"/></svg>
                    @else
                        {{ $icon }}
                    @endif
                </span>
            @endif

            <div class="magna-features__body">
                @if($title !== '')
                    <h3 class="magna-features__title">
                        @if($url !== '')
                            <a href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($url) }}">{{ $title }}</a>
                        @else
                            {{ $title }}
                        @endif
                        @if($tag !== '')
                            <span class="magna-features__tag">{{ $tag }}</span>
                        @endif
                    </h3>
                @endif
                @if($description !== '')
                    <p class="magna-features__description">{{ $description }}</p>
                @endif
            </div>

            @if($status !== '')
                <span class="magna-features__status" data-tone="{{ $tone }}">{{ $status }}</span>
            @endif
        </div>
    @endforeach
</div>
