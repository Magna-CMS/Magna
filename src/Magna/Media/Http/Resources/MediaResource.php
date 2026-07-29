<?php

declare(strict_types=1);

namespace Magna\Media\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Magna\Media\Media;
use Magna\Media\MediaUrlResolver;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /**
     * The wrapped model. Redeclared from JsonResource (untyped there) so the
     * computed url/srcset fields can hand a typed Media to MediaUrlResolver.
     *
     * @var Media
     */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $urls = app(MediaUrlResolver::class);

        return [
            'id' => $this->id,
            'disk' => $this->disk,
            'path' => $this->path,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'title' => $this->title,
            'url' => $urls->publicUrl($this->resource),
            'srcset' => $urls->srcset($this->resource),
            'folder_id' => $this->folder_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
