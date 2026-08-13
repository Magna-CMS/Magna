@php
    $rawItems = $block['data']['items'] ?? [];
    $items = is_array($rawItems) ? $rawItems : (json_decode(is_string($rawItems) ? $rawItems : '[]', true) ?: []);
@endphp
<div class="b-features">
    @foreach($items as $item)
        @continue(!is_array($item))
        <div class="b-feature">
            <h3>
                @if(!empty($item['icon']))<span aria-hidden="true">{{ $item['icon'] }}</span>@endif
                {{ $item['title'] ?? '' }}
            </h3>
            @if(!empty($item['description']))
                <p>{{ $item['description'] }}</p>
            @endif
        </div>
    @endforeach
</div>
