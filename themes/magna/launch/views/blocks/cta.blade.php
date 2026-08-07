@php
    $headline = $block['data']['headline'] ?? '';
    $body = $block['data']['body'] ?? '';
    $pLabel = $block['data']['button_primary_label'] ?? '';
    $pUrl = \Magna\Blocks\Support\SafeUrl::sanitize($block['data']['button_primary_url'] ?? '#');
    $sLabel = $block['data']['button_secondary_label'] ?? '';
    $sUrl = \Magna\Blocks\Support\SafeUrl::sanitize($block['data']['button_secondary_url'] ?? '#');
@endphp
<div class="b-cta">
    @if($headline)
        <h2>{{ $headline }}</h2>
    @endif
    @if($body)
        <p>{{ $body }}</p>
    @endif
    @if($pLabel || $sLabel)
        <div class="b-hero__ctas">
            @if($pLabel)
                <a href="{{ $pUrl }}" class="btn btn--primary">{{ $pLabel }}</a>
            @endif
            @if($sLabel)
                <a href="{{ $sUrl }}" class="btn btn--outline">{{ $sLabel }}</a>
            @endif
        </div>
    @endif
</div>
