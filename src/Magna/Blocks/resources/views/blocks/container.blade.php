{{-- Block: container --}}
@php
    // The element is chosen from a fixed list, so a document can never put
    // an arbitrary tag name into the markup. Anything unrecognised — an
    // older document, a hand-edited one — falls back to a div.
    $allowed = ['div', 'section', 'article', 'aside'];
    $tag = $block['data']['tag'] ?? 'div';
    $tag = in_array($tag, $allowed, true) ? $tag : 'div';

    // Children arrive already rendered by partials/block.blade.php, which
    // is the ONE place blocks become HTML. This view only wraps them.
    $children = $childrenHtml ?? '';

    // Marked rather than left to CSS `:empty`: the wrapper is written with
    // indentation, so an "empty" container still holds whitespace and
    // `:empty` would never match it. The builder draws a drop zone on this
    // class; the public page ignores it.
    $emptyClass = trim($children) === '' ? ' magna-container--empty' : '';
@endphp
<{{ $tag }} class="magna-block magna-block--container magna-container{{ $emptyClass }}">{!! $children !!}</{{ $tag }}>
