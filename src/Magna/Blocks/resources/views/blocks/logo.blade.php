{{-- Block: logo --}}
@php
    /*
     * A site's logo: an image when there is one, the site's name when there
     * is not.
     *
     * The text fallback is not a placeholder — a header that renders
     * nothing while somebody is choosing an image is a header that looks
     * broken, and plenty of sites are wordmarks with no image at all.
     */
    $mediaId = $block['data']['media_id'] ?? null;
    $media = $mediaId ? \Magna\Media\Media::find($mediaId) : null;
    $src = $media ? \Illuminate\Support\Facades\Storage::disk($media->disk)->url($media->path) : null;

    $text = trim((string) ($block['data']['text'] ?? ''));
    $alt = trim((string) ($block['data']['alt'] ?? ''));
    $url = $block['data']['url'] ?? '/';
    $height = (string) ($block['data']['height'] ?? '40px');

    // A length, and nothing that could carry a declaration of its own.
    $height = preg_match('/^[0-9.]+(px|rem|em|%|vh)$/', $height) === 1 ? $height : '40px';

    /*
     * A logo is usually a LINK to home; on the home page itself it is
     * often the page's main heading. Saying which is an editor's call,
     * because only they know what the page is — and an h1 that is wrong is
     * an accessibility problem, not a style one.
     */
    $isHeading = ($block['data']['heading'] ?? 'no') === 'yes';

    // Alt text describes the LOGO, so it falls back to the wordmark rather
    // than to the empty string, which would leave the link unnamed.
    $label = $alt !== '' ? $alt : ($text !== '' ? $text : 'Home');
@endphp
<div class="magna-block magna-block--logo magna-logo">
    @if($isHeading)<h1 class="magna-logo__heading">@endif
    <a href="{{ \Magna\Blocks\Support\SafeUrl::sanitize(is_string($url) && $url !== '' ? $url : '/') }}"
       class="magna-logo__link"
       @if($src === null && $text === '') aria-label="{{ $label }}" @endif>
        @if($src !== null)
            <img src="{{ $src }}"
                 alt="{{ $label }}"
                 class="magna-logo__image"
                 style="height:{{ $height }};width:auto"
                 decoding="async"
                 @if($media && $media->width && $media->height) width="{{ $media->width }}" height="{{ $media->height }}" @endif>
        @else
            <span class="magna-logo__text">{{ $text !== '' ? $text : \Magna\Settings\GeneralSettings::get()->site_name }}</span>
        @endif
    </a>
    @if($isHeading)</h1>@endif
</div>
