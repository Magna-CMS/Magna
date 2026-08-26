{{--
    Block: features — Nova's marketing workhorse.

    One block covers every repeating pattern on the Magna site: the "why"
    cards, the use-case chips, the feature rows with status pills, the
    numbered steps, the architecture layers and every tick list. Which one
    you get is decided by the section's CSS class (nova-cards, nova-chips,
    nova-rows, nova-steps, nova-layers, nova-marquee) — a field an editor
    fills in from the builder's own inspector.

    Over the core view this adds three optional item keys, all ignored by
    every other theme:

      status  a pill on the right of a row  ("Available", "Alpha 1.0.0")
      tone    what colours that pill        (avail | dev | plan)
      tag     a small label on a layer row  ("Yours", "Opt-in")

    `icon` keeps its core meaning — a short string — and additionally
    resolves against the sprite the layout defines when it names one of
    the icons below. A name nobody knows prints as text, which is what
    makes an emoji still work.
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
        'arrow-right', 'arrow-up',
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
                        <svg class="nova-i"><use href="#nova-i-{{ $icon }}"/></svg>
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
                    </h3>
                @endif
                @if($description !== '')
                    <p class="magna-features__description">{{ $description }}</p>
                @endif
            </div>

            @if($status !== '')
                <span class="magna-features__status" data-tone="{{ $tone }}">{{ $status }}</span>
            @endif
            @if($tag !== '')
                <span class="magna-features__tag">{{ $tag }}</span>
            @endif
        </div>
    @endforeach
</div>
