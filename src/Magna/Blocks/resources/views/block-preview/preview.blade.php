<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Block Preview</title>
    <style>
        /* Minimal debug chrome — intentionally unstyled except structural indicators */
        body { margin: 0; font-family: system-ui, sans-serif; }
        .magna-section { border: 1px dashed #6366f1; margin: 1rem; padding: 1rem; position: relative; }
        .magna-section::before { content: 'section'; font-size: 10px; color: #6366f1; position: absolute; top: 4px; left: 6px; }
        .magna-columns { display: flex; gap: 1rem; margin-top: 1.5rem; }
        .magna-column { border: 1px dashed #10b981; padding: 0.75rem; position: relative; min-width: 0; }
        .magna-column::before { content: 'col(' attr(data-span) ')'; font-size: 10px; color: #10b981; position: absolute; top: 3px; left: 5px; }
        .magna-column > * { margin-top: 1rem; }
        .magna-block { border: 1px dashed #f59e0b; padding: 0.5rem; }
        .magna-block::before { content: 'block: ' attr(data-handle); font-size: 10px; color: #f59e0b; display: block; margin-bottom: 0.25rem; }
        .magna-unknown-block { color: #dc2626; border: 1px dashed #dc2626; padding: 0.5rem; font-size: 12px; }
    </style>
</head>
<body>

@foreach($tree->sections as $section)
    @php
        // Build inline CSS custom properties from tokenOverrides
        $overrides = $section->tokenOverrides();
        $styleAttr = '';
        if (!empty($overrides)) {
            $props = array_map(
                fn(string $k, string $v) => '--' . $k . ':' . $v,
                array_keys($overrides),
                array_values($overrides)
            );
            $styleAttr = implode(';', $props);
        }

        $anchor   = $section->settings['anchor'] ?? '';
        $cssClass = $section->settings['cssClass'] ?? '';
        $maxWidth = $section->settings['maxWidth'] ?? '2xl';
    @endphp
    <section
        class="magna-section{{ $cssClass ? ' '.$cssClass : '' }}"
        @if($anchor) id="{{ $anchor }}" @endif
        @if($styleAttr) style="{{ $styleAttr }}" @endif
        data-section-id="{{ $section->id }}"
        data-max-width="{{ $maxWidth }}"
    >
        <div class="magna-columns">
            @foreach($section->columns as $column)
                <div class="magna-column"
                     data-column-id="{{ $column->id }}"
                     data-span="{{ $column->span }}"
                     style="flex: {{ $column->span }} {{ $column->span }} 0%">

                    @foreach($column->blocks as $block)
                        @php $def = $registry->get($block->block); @endphp
                        @if($def)
                            <div class="magna-block" data-block-id="{{ $block->id }}" data-handle="{{ $block->block }}">
                                @include('magna::blocks.' . $block->block, [
                                    'block' => $resolver->viewPayload($block),
                                    'definition' => $def,
                                ])
                            </div>
                        @else
                            <div class="magna-unknown-block" data-block-id="{{ $block->id }}">
                                Unknown block type: <strong>{{ $block->block }}</strong>
                                (plugin may be disabled — block preserved)
                            </div>
                        @endif
                    @endforeach

                </div>
            @endforeach
        </div>
    </section>
@endforeach

@if(empty($tree->sections))
    <p style="color:#94a3b8;text-align:center;padding:4rem;font-size:14px;">
        No sections in this entry yet.
    </p>
@endif

</body>
</html>
