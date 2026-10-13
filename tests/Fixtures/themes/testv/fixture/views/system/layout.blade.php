<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    @if($tokensCss !== '')
        <style>{!! $tokensCss !!}</style>
    @endif
</head>
<body data-theme="fixture-theme-layout">
<header>fixture-theme-header</header>
{{-- The pageUrl closure: a layout links its own CTA by slug; a draft or
     unknown slug resolves null and the link degrades away. --}}
@php($ctaUrl = $pageUrl('download'))
@if($ctaUrl !== null)
    <a class="fixture-theme-cta" href="{{ $ctaUrl }}">Download</a>
@endif
<main>
    @include('magna-pages::partials.sections')
</main>
<footer>fixture-theme-footer</footer>
</body>
</html>
