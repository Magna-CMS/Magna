{{--
    Stands in for a block whose DEFAULT view still carries its behaviour
    inline — the shape the scheme toggle had before it moved to a
    block-behaviour view. Mounted on the `magna` namespace by the audit test
    so the auditor finds it as `magna::blocks.scripty`; no such block is
    registered, because the audit reads views and never the registry.
--}}
<div class="scripty" data-magna-scripty>{{ $block['data']['text'] ?? '' }}</div>
@once
    <script>
        document.querySelectorAll('[data-magna-scripty]').forEach(function (el) { el.hidden = false })
    </script>
@endonce
