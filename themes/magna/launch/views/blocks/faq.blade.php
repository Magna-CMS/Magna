@php
    $rawItems = $block['data']['items'] ?? [];
    $items = is_array($rawItems) ? $rawItems : (json_decode(is_string($rawItems) ? $rawItems : '[]', true) ?: []);
@endphp
<div class="b-faq">
    @foreach($items as $item)
        @continue(!is_array($item))
        <details>
            <summary>{{ $item['question'] ?? '' }}</summary>
            <div class="b-faq__a">{{ $item['answer'] ?? '' }}</div>
        </details>
    @endforeach
</div>
