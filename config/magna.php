<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Magna
|--------------------------------------------------------------------------
|
| Yours to edit. Replace this line with an array and it becomes the file that
| decides - core fills in only the keys you leave out, so you keep everything
| you set and still receive settings added by later releases.
|
| As shipped it hands straight to core's own copy, which travels with the code
| in `config/defaults`, which a core update DOES replace - unlike this file,
| which is yours and is left alone. A key defined only here would never reach
| a site that updated rather than installed fresh. It happened twice, to two
| security controls, before this file was reduced to one line.
|
| To decline a default, set the key to `null` rather than deleting it. A
| deleted key is indistinguishable from one this file predates, and core will
| fill it back in; an explicit null is left alone forever.
|
| See Magna\Support\ConfigDefaults.
*/

return require __DIR__.'/defaults/magna.php';
