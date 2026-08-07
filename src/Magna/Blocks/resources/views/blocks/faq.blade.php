{{-- Block: faq (native <details>/<summary>, zero JS) --}}
@php
    // Repeater field stores a real array; legacy json-field era stored a
    // JSON string — accept both so old content keeps rendering.
    $rawItems = $block['data']['items'] ?? [];
    $items = is_array($rawItems) ? $rawItems : (json_decode(is_string($rawItems) ? $rawItems : '[]', true) ?: []);
@endphp
<div class="magna-block magna-block--faq magna-faq">
    @foreach($items as $item)
        <details class="magna-faq__item">
            <summary class="magna-faq__question">{{ $item['question'] ?? '' }}</summary>
            <div class="magna-faq__answer">{{ $item['answer'] ?? '' }}</div>
        </details>
    @endforeach
</div>
