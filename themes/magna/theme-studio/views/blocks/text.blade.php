{{-- Raw output ONLY via the sanitized `_resolved.body` (see core text view). --}}
<div class="b-prose">
    {!! $block['_resolved']['body'] ?? e($block['data']['body'] ?? '') !!}
</div>
