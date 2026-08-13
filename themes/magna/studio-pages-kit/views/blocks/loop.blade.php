{{--
    Studio-native Loop: card grid over the conventional item keys. Same
    security contract as the plugin's default view — everything escaped,
    urls through SafeUrl, empty source renders a gap.
--}}
@php
    $items = $block['_resolved']['items'] ?? [];
    $heading = $block['data']['heading'] ?? '';
@endphp

<div class="magna-loop magna-loop--studio-cards">
    @once
        <style>
            .magna-loop--studio-cards .magna-loop__heading { margin-bottom: 1.5rem; }
            .magna-loop--studio-cards .magna-loop__items { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); list-style: none; margin: 0; padding: 0; }
            .magna-loop--studio-cards .magna-loop__item { background: var(--color-loop-card, #292524); border-radius: var(--radius, 0.125rem); padding: 1.5rem; display: flex; flex-direction: column; gap: 0.5rem; }
            .magna-loop--studio-cards .magna-loop__title { font-family: Georgia, "Times New Roman", serif; font-size: 1.2rem; font-weight: 700; }
            .magna-loop--studio-cards .magna-loop__description { color: var(--color-muted, #a8a29e); font-size: 0.95rem; margin: 0; }
            .magna-loop--studio-cards .magna-loop__date { color: var(--color-primary, #f59e0b); font-size: 0.8rem; letter-spacing: 0.1em; text-transform: uppercase; }
        </style>
    @endonce

    @if(is_string($heading) && $heading !== '')
        <h2 class="magna-loop__heading">{{ $heading }}</h2>
    @endif

    @if($items === [])
        {{-- An empty source renders nothing extra: a gap, not a broken box. --}}
    @else
        <ul class="magna-loop__items">
            @foreach($items as $item)
                <li class="magna-loop__item">
                    @if(!empty($item['date']))
                        <time class="magna-loop__date">{{ $item['date'] }}</time>
                    @endif

                    @if(!empty($item['url']))
                        <a class="magna-loop__title" href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['url']) }}">
                            {{ $item['title'] ?? 'Untitled' }}
                        </a>
                    @else
                        <span class="magna-loop__title">{{ $item['title'] ?? 'Untitled' }}</span>
                    @endif

                    @if(!empty($item['description']))
                        <p class="magna-loop__description">{{ $item['description'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
