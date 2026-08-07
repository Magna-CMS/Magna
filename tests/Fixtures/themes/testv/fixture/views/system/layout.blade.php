<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    @if($tokensCss !== '')
        <style>{!! $tokensCss !!}</style>
    @endif
</head>
<body data-theme="fixture-theme-layout">
<header>fixture-theme-header</header>
<main>
    @include('magna-pages::partials.sections')
</main>
<footer>fixture-theme-footer</footer>
</body>
</html>
