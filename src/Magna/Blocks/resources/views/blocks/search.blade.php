{{-- Block: search --}}
@php
    /*
     * A search FORM. Where it leads is the site's business — core owns no
     * search results page, and a box that submits into a 404 is worse than
     * no box at all, so this renders nothing until an editor says where
     * results live.
     *
     * GET, not POST: a search is a request to look at something, its result
     * should be linkable and bookmarkable, and the browser's own history
     * should be able to take you back to it.
     */
    $action = $block['data']['action'] ?? '';
    $action = is_string($action) && $action !== ''
        ? \Magna\Blocks\Support\SafeUrl::sanitize($action)
        : '';

    $placeholder = (string) ($block['data']['placeholder'] ?? 'Search…');
    $label = trim((string) ($block['data']['label'] ?? ''));
    $label = $label === '' ? 'Search this site' : $label;

    // A query parameter is a name, so it may only look like one.
    $param = (string) ($block['data']['param'] ?? 'q');
    $param = preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $param) === 1 ? $param : 'q';
@endphp
@if($action !== '')
    <form class="magna-block magna-block--search magna-search" role="search" method="get" action="{{ $action }}">
        <label class="magna-search__label" for="magna-search-{{ $block['id'] ?? 'field' }}">{{ $label }}</label>
        <input id="magna-search-{{ $block['id'] ?? 'field' }}"
               class="magna-search__input"
               type="search"
               name="{{ $param }}"
               placeholder="{{ $placeholder }}">
        <button class="magna-search__submit" type="submit">{{ $label }}</button>
    </form>
    @once
        <style>
            /* The label names the field for a screen reader and stays out
               of the way visually — a magnifying glass with no name is a
               button that announces as "button". */
            .magna-search__label {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip-path: inset(50%);
                white-space: nowrap;
            }
            .magna-search { display: flex; gap: 0.5rem; align-items: center; }
        </style>
    @endonce
@endif
