{{--
    Previous / next navigation for a paginated public listing. Expects
    $pagination (Magna\Frontend\Pagination); renders nothing for a listing
    that fits on one page. Class names are a stable contract themes style:
    nav.magna-pagination > a.magna-pagination__prev, span.magna-pagination__status,
    a.magna-pagination__next.
--}}
@if (isset($pagination) && $pagination instanceof \Magna\Frontend\Pagination && $pagination->hasPages())
    <nav class="magna-pagination" aria-label="{{ __('Page navigation') }}">
        @if ($pagination->hasPrevious())
            <a class="magna-pagination__prev" href="{{ $pagination->previousUrl() }}" rel="prev">{{ __('Newer') }}</a>
        @endif
        <span class="magna-pagination__status">{{ __('Page :page of :last', ['page' => $pagination->page, 'last' => $pagination->lastPage]) }}</span>
        @if ($pagination->hasNext())
            <a class="magna-pagination__next" href="{{ $pagination->nextUrl() }}" rel="next">{{ __('Older') }}</a>
        @endif
    </nav>
@endif
