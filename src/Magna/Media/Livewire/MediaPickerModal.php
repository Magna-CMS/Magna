<?php

declare(strict_types=1);

namespace Magna\Media\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Magna\Media\Media;
use Magna\Media\MediaIngestor;

/**
 * Global media-picker modal for the Magna admin panel.
 *
 * Include once in any Filament page or Livewire component view:
 *
 *   <livewire:magna-media-picker />
 *
 * Open the picker from PHP:
 *
 *   $this->dispatch('magna:open-media-picker', target: 'logo');
 *
 * Open the picker from Alpine / blade:
 *
 *   @click="$dispatch('magna:open-media-picker', { target: 'logo' })"
 *
 * Listen for the result in the host component:
 *
 *   #[On('magna:media-selected')]
 *   public function onMediaSelected(string $path, string $url, string $disk, string $target): void
 *   {
 *       if ($target === 'logo') { $this->logoPath = $path; }
 *   }
 *
 * Event reference
 * ───────────────
 *   magna:open-media-picker  { target?: string, mimeFilter?: string }
 *     → opens the picker; target is echoed back with the result so the host
 *       can distinguish between multiple pickers on the same page.
 *       mimeFilter defaults to 'image/*'.
 *
 *   magna:media-selected  { path, url, disk, target }
 *     → dispatched globally when the user confirms a selection or uploads.
 *
 *   magna:media-picker-closed  { target }
 *     → dispatched when the picker is dismissed without a selection.
 */
class MediaPickerModal extends Component
{
    use WithFileUploads;

    public bool $open = false;

    /** Echoed back with every result event so the host can route by field. */
    public string $target = '';

    /**
     * MIME filter applied when loading library images.
     * Accepts glob-style prefix, e.g. 'image/*', 'application/pdf', or '*'.
     */
    public string $mimeFilter = 'image/*';

    /** @var list<array{name:string,url:string,path:string,disk:string}> */
    public array $files = [];

    /**
     * Temporary upload from the device-upload input inside the picker.
     *
     * Left untyped natively so Livewire's file-upload hydration is unaffected;
     * the type is declared for static analysis only.
     *
     * @var TemporaryUploadedFile|null
     */
    public $deviceUpload = null;

    // ── Open / close ─────────────────────────────────────────────────────────

    #[On('magna:open-media-picker')]
    public function openPicker(string $target = '', string $mimeFilter = 'image/*'): void
    {
        $this->target = $target;
        $this->mimeFilter = $mimeFilter;
        $this->loadFiles();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->dispatch('magna:media-picker-closed', target: $this->target);
    }

    // ── File loading ──────────────────────────────────────────────────────────

    public function loadFiles(): void
    {
        $query = Media::query()
            ->whereNull('deleted_at')
            ->orderByDesc('created_at');

        if ($this->mimeFilter !== '*') {
            // Support glob prefix like 'image/*' or exact type like 'application/pdf'
            if (str_ends_with($this->mimeFilter, '/*')) {
                $prefix = rtrim($this->mimeFilter, '*');
                $query->where('mime_type', 'like', $prefix.'%');
            } else {
                $query->where('mime_type', $this->mimeFilter);
            }
        }

        // array_values() so the result is a proper list<> matching $files.
        $this->files = array_values($query
            ->get()
            ->map(fn (Media $m) => [
                'name' => filled($m->title) ? $m->title : $m->original_filename,
                'url' => Storage::disk($m->disk)->url($m->path),
                'path' => $m->path,
                'disk' => $m->disk,
            ])
            ->all());
    }

    // ── Upload from device ────────────────────────────────────────────────────

    public function updatedDeviceUpload(): void
    {
        if ($this->deviceUpload === null) {
            return;
        }

        try {
            $media = app(MediaIngestor::class)->ingest(
                (string) $this->deviceUpload->getRealPath(),
                $this->deviceUpload->getClientOriginalName(),
                'public',
            );

            $this->deviceUpload = null;
            $this->confirm($media->path, 'public');
        } catch (\Throwable $e) {
            $this->deviceUpload = null;
            Notification::make()->title('Upload failed')->body($e->getMessage())->danger()->send();
        }
    }

    // ── Confirm selection ─────────────────────────────────────────────────────

    /** Called from the Alpine "Choose" button or on double-click. */
    public function confirm(string $path, string $disk = 'public'): void
    {
        $url = Storage::disk($disk)->url($path);

        // Include the media id so hosts can store a stable reference (block
        // documents store media by ULID, not by disk path). Additive event
        // field — existing listeners that don't declare it are unaffected.
        $media = Media::query()
            ->where('path', $path)
            ->where('disk', $disk)
            ->first();

        $this->dispatch('magna:media-selected',
            path: $path,
            url: $url,
            disk: $disk,
            target: $this->target,
            id: $media !== null ? $media->id : '',
        );

        $this->open = false;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('magna::livewire.media-picker-modal');
    }
}
