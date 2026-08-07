<?php

declare(strict_types=1);

namespace Magna\Blocks\Resolution;

use Magna\Blocks\Sanitization\RichTextSanitizer;

/**
 * Resolves the `text` block: sanitizes the richtext body for unescaped
 * rendering. The view renders `_resolved.body` raw and falls back to
 * ESCAPED stored data when no resolver ran — raw output exists only on the
 * sanitized path, so a bypassed resolve step fails safe.
 */
final class TextBlockResolver implements ResolvesBlockData
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function handle(): string
    {
        return 'text';
    }

    public function resolve(array $data): array
    {
        $body = $data['body'] ?? '';

        return ['body' => is_string($body) ? $this->sanitizer->sanitize($body) : ''];
    }
}
