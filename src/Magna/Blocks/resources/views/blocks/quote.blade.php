{{--
    Block: quote — a pulled quotation with optional attribution.

    Everything is escaped. A quote is the block most likely to be pasted in
    from somewhere else, so it is the one that must never render markup it
    was handed.
--}}
@php
    $quote = trim((string) ($block['data']['quote'] ?? ''));
    $attribution = trim((string) ($block['data']['attribution'] ?? ''));
    $source = trim((string) ($block['data']['source'] ?? ''));
@endphp
@if($quote !== '')
    <figure class="magna-block magna-block--quote magna-quote">
        <blockquote class="magna-quote__text">{{ $quote }}</blockquote>

        @if($attribution !== '' || $source !== '')
            <figcaption class="magna-quote__attribution">
                @if($attribution !== '')
                    <span class="magna-quote__name">{{ $attribution }}</span>
                @endif
                @if($source !== '')
                    <cite class="magna-quote__source">{{ $source }}</cite>
                @endif
            </figcaption>
        @endif
    </figure>
@endif
