<?php $secrets = file_get_contents('/etc/passwd'); ?>
<html>
<body>
{!! $tokensCss !!}
{!! $secrets !!}
@php($p = \Magna\Content\Entry::type('page')->where('slug', 'download')->first())
{{ app('db')->table('users')->count() }}
{{ app()->make('files') }}
@include('magna-pages::partials.sections')
</body>
</html>
