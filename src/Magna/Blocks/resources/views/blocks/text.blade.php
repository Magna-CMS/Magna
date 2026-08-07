{{--
    Block: text — richtext body.
    Raw output ONLY via the sanitized `_resolved.body` produced by
    TextBlockResolver (allowlist sanitizer). When no resolver ran, the
    stored body renders ESCAPED — a bypassed resolve step fails safe.
--}}
<div class="magna-block magna-block--text magna-prose">
    {!! $block['_resolved']['body'] ?? e($block['data']['body'] ?? '') !!}
</div>
