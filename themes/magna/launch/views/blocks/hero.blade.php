@php
    $layout = $block['data']['layout'] ?? 'centered';
    $headline = $block['data']['headline'] ?? '';
    $sub = $block['data']['subheadline'] ?? '';
    $ctaPLabel = $block['data']['cta_primary_label'] ?? '';
    $ctaPUrl = \Magna\Blocks\Support\SafeUrl::sanitize($block['data']['cta_primary_url'] ?? '#');
    $ctaSLabel = $block['data']['cta_secondary_label'] ?? '';
    $ctaSUrl = \Magna\Blocks\Support\SafeUrl::sanitize($block['data']['cta_secondary_url'] ?? '#');
@endphp
<div class="b-hero b-hero--{{ $layout }}">
    @if($headline)
        <h1>{{ $headline }}</h1>
    @endif
    @if($sub)
        <p class="b-hero__sub">{{ $sub }}</p>
    @endif
    @if($ctaPLabel || $ctaSLabel)
        <div class="b-hero__ctas">
            @if($ctaPLabel)
                <a href="{{ $ctaPUrl }}" class="btn btn--primary">{{ $ctaPLabel }}</a>
            @endif
            @if($ctaSLabel)
                <a href="{{ $ctaSUrl }}" class="btn btn--outline">{{ $ctaSLabel }}</a>
            @endif
        </div>
    @endif
</div>
