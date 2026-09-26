<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Forwarder - core's defaults live in src/Magna/Config/defaults
|--------------------------------------------------------------------------
|
| Same shape and same reason as config/defaults/magna.php beside it.
*/

return is_file(dirname(__DIR__, 2).'/src/Magna/Config/defaults/trustedproxy.php')
    ? require dirname(__DIR__, 2).'/src/Magna/Config/defaults/trustedproxy.php'
    : [];
