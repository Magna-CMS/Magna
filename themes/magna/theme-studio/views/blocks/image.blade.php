@php
    $mediaId = $block['data']['media_id'] ?? null;
    $alt = $block['data']['alt'] ?? '';
    $caption = $block['data']['caption'] ?? '';
    $media = $mediaId ? \Magna\Media\Media::find($mediaId) : null;
    $url = $media ? \Illuminate\Support\Facades\Storage::disk($media->disk)->url($media->path) : null;
@endphp
@if($url)
<div class="b-image">
    <figure>
        <img src="{{ $url }}" alt="{{ $alt }}" loading="lazy" decoding="async"
             @if($media->width && $media->height) width="{{ $media->width }}" height="{{ $media->height }}" @endif>
        @if($caption)
            <figcaption>{{ $caption }}</figcaption>
        @endif
    </figure>
</div>
@endif
