<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Yours to edit. Replace this line with an array and it becomes the file that
| decides; core fills in only what you leave out.
|
| As shipped it hands to core's own copy in `config/defaults`, which a core
| update replaces - unlike this file, which is left alone. This file did not
| exist before 1.4.1, so on a site that updated into 1.4.x it may still not:
| the backfill covers that, because it works on the namespace rather than on
| the file.
|
| See Magna\Support\ConfigDefaults.
*/

return is_file(__DIR__.'/defaults/trustedproxy.php')
    ? require __DIR__.'/defaults/trustedproxy.php'
    : [];
