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
| in `src/Magna/Config/defaults` (reached through `config/defaults`), which a
| core update DOES replace - unlike this file, which is yours and is left
| alone. A key defined only here would never reach a site that updated rather
| than installed fresh. It happened twice, to two security controls, before
| this file was reduced to one line.
|
| To decline a default, set the key to `null` rather than deleting it. A
| deleted key is indistinguishable from one this file predates, and core will
| fill it back in; an explicit null is left alone forever.
|
| See Magna\Support\ConfigDefaults.
*/

/*
 * Guarded rather than a bare require. A core file that has gone missing -
 * a half-finished upload, an extraction that stopped - is a broken install
 * either way, but a fatal here happens inside the config bootstrapper,
 * before the error handler exists: the operator gets a blank page and no
 * panel to fix it from. Empty leaves every magna.* key null, which is bad
 * and visible, and the site still boots to say so.
 */
return is_file(__DIR__.'/defaults/magna.php')
    ? require __DIR__.'/defaults/magna.php'
    : [];
