{{-- Block: container --}}
@php
    // The element is chosen from a fixed list, so a document can never put
    // an arbitrary tag name into the markup. Anything unrecognised — an
    // older document, a hand-edited one — falls back to a div.
    $allowed = ['div', 'section', 'article', 'aside', 'figure', 'nav'];
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

    // A composite built from text — a device mockup, a diagram — is one
    // thing to a reader and a pile of fragments to a screen reader. These
    // two let an author say what it actually is. Same fixed-list treatment
    // as the tag: a role is never free text.
    $roles = ['img', 'group', 'figure'];
    $role = $block['data']['role'] ?? '';
    $role = in_array($role, $roles, true) ? $role : '';

    $label = trim((string) ($block['data']['label'] ?? ''));

    // A theme's own classes. Until this existed the only styling hook an
    // author had was a SECTION's cssClass, and a section is a whole band:
    // when one band holds two patterns its class list holds both, and a rule
    // written for one pattern reaches the other pattern's blocks. A class
    // here is scoped to the blocks this container actually holds, which is
    // the scope a theme rule usually wanted.
    //
    // Tokens carrying a character that would have to be escaped to be safe
    // are DROPPED rather than escaped. No legitimate class name contains a
    // quote or an angle bracket, and escaping one would leave `&quot;` in the
    // class list — safe markup that styles nothing and reads as a bug. What
    // real classes use survives: BEM underscores, and Tailwind's colons,
    // slashes and brackets. The escape on output stays either way.
    $classes = array_filter(
        preg_split('/\s+/', (string) ($block['data']['cssClass'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        static fn (string $token): bool => preg_match('/^[^"\'<>&=`\\\\]+$/u', $token) === 1,
    );

    $cssClass = $classes === [] ? '' : ' '.implode(' ', $classes);

    // Composed here rather than with @if inside the tag: a conditional in the
    // markup puts the attributes on their own lines, and this element's exact
    // opening tag is something the suite asserts. A container that names
    // nothing must emit exactly what it emitted before.
    $attributes = '';

    if ($role !== '') {
        $attributes .= ' role="'.e($role).'"';
    }

    if ($label !== '') {
        $attributes .= ' aria-label="'.e($label).'"';
    }
@endphp
<{{ $tag }} class="magna-block magna-block--container magna-container{{ $emptyClass }}{{ $cssClass }}"{!! $attributes !!}>{!! $children !!}</{{ $tag }}>
