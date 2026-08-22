{{-- Block: icon --}}
@php
    /*
     * Colour and alignment are deliberately NOT fields here: the Style tab
     * already offers text colour and text align for any block, and the
     * icon is stroked in `currentColor`, so both already reach it. A field
     * that duplicates a working control is a second place to set the same
     * thing and a first place for them to disagree.
     */
    $sizes = ['sm' => 20, 'md' => 28, 'lg' => 40, 'xl' => 56];

    $size = $block['data']['size'] ?? 'md';
    $pixels = $sizes[is_string($size) ? $size : 'md'] ?? $sizes['md'];

    $label = trim((string) ($block['data']['label'] ?? ''));
    $url = $block['data']['url'] ?? '';
    $target = $block['data']['target'] ?? '_self';

    // A NAME, never markup: the registry is the only thing that turns one
    // into SVG, and a name it does not know draws nothing at all.
    $name = $block['data']['name'] ?? '';
    $svg = is_string($name)
        ? app(\Magna\Blocks\Icons\IconRegistry::class)
            ->svg($name, $label !== '' ? $label : null, 'magna-icon__glyph', $pixels)
        : null;
@endphp
@if($svg !== null)
    <div class="magna-block magna-block--icon magna-icon magna-icon--{{ is_string($size) ? $size : 'md' }}">
        @if(is_string($url) && $url !== '')
            <a href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($url) }}"
               class="magna-icon__link"
               @if($target === '_blank') target="_blank" rel="noopener noreferrer" @endif>{!! $svg !!}</a>
        @else
            {!! $svg !!}
        @endif
    </div>
@endif
