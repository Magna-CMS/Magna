{{--
    Block: file — a download link for something in the media library.

    `download` rather than a plain link: a PDF opens in the browser's viewer
    otherwise, which is not what someone clicking "Annual report (PDF)"
    asked for. The size and type are printed because a link that silently
    starts a 40MB download on a phone is the one people complain about.
--}}
@php
    $mediaId = $block['data']['media_id'] ?? null;
    $media = $mediaId ? \Magna\Media\Media::find($mediaId) : null;
    $url = $media ? \Illuminate\Support\Facades\Storage::disk($media->disk)->url($media->path) : null;

    $label = trim((string) ($block['data']['label'] ?? ''));
    $label = $label !== '' ? $label : ($media?->title ?? $media?->original_filename ?? '');

    $description = trim((string) ($block['data']['description'] ?? ''));

    $extension = '';
    if ($media !== null) {
        $dot = strrpos($media->original_filename, '.');
        $extension = $dot === false ? '' : strtoupper(substr($media->original_filename, $dot + 1));
    }

    // Binary units, one decimal, because a file listing that says
    // "0.4296875 MB" is a number nobody asked for.
    $size = '';
    if ($media !== null && $media->size > 0) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $media->size;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        $size = ($unit === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$unit];
    }
@endphp
@if($url && $label !== '')
    <div class="magna-block magna-block--file magna-file">
        <a class="magna-file__link" href="{{ $url }}" download>
            <span class="magna-file__label">{{ $label }}</span>

            @if($extension !== '' || $size !== '')
                <span class="magna-file__meta">
                    {{ trim($extension.($extension !== '' && $size !== '' ? ' · ' : '').$size) }}
                </span>
            @endif
        </a>

        @if($description !== '')
            <p class="magna-file__description">{{ $description }}</p>
        @endif
    </div>
@endif
