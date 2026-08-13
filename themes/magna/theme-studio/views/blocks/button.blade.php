@php
    $label = $block['data']['label'] ?? '';
    $url = \Magna\Blocks\Support\SafeUrl::sanitize($block['data']['url'] ?? '#');
    $style = ($block['data']['style'] ?? 'primary') === 'outline' ? 'outline' : 'primary';
    $target = $block['data']['target'] ?? '_self';
@endphp
@if($label)
    <a href="{{ $url }}" class="btn btn--{{ $style }}"
       @if($target === '_blank') target="_blank" rel="noopener noreferrer" @endif
    >{{ $label }}</a>
@endif
