<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Forwarder - core's defaults live in src/Magna/Config/defaults
|--------------------------------------------------------------------------
|
| Kept so a `config/magna.php` written against 1.4.3 keeps resolving, and so
| an older updater that overlays `config/defaults` delivers something sane.
| The real file moved into `src/Magna`, the one path every core updater has
| ever overlaid; the note at the top of it says why. Nothing here is a site's
| to edit.
*/

return is_file(dirname(__DIR__, 2).'/src/Magna/Config/defaults/magna.php')
    ? require dirname(__DIR__, 2).'/src/Magna/Config/defaults/magna.php'
    : [];
