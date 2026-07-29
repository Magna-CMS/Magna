<?php

use Illuminate\Support\Facades\Route;
use Magna\Licensing\LicenseController;

// Licence actions behind the Magna Account page's Licences section. Under
// the 'web' group (session + CSRF) and 'auth', same as Account Centre's own
// routes — these are admin form posts, never machine-to-machine calls. The
// server-to-server half of licensing lives in Magna\Licensing\LicenseClient
// and talks outward only.
Route::prefix('licensing')->name('licensing.')->middleware('auth')->group(function (): void {
    // One redeem route for every kind of key — the controller recognises a
    // Magna key by shape and treats anything else as an external purchase code.
    Route::post('/redeem', [LicenseController::class, 'redeem'])->name('redeem');
    Route::post('/start-trial', [LicenseController::class, 'startTrial'])->name('start-trial');
    Route::post('/install', [LicenseController::class, 'install'])->name('install');
    Route::post('/update', [LicenseController::class, 'update'])->name('update');
    Route::post('/deactivate', [LicenseController::class, 'deactivate'])->name('deactivate');
    Route::post('/verify', [LicenseController::class, 'verifyNow'])->name('verify');

    // A GET, because it is a download and a link has to work. Safe as one:
    // it reads one of the signed-in admin's own invoices and changes
    // nothing.
    Route::get('/invoice/{invoice}', [LicenseController::class, 'invoice'])
        ->whereNumber('invoice')
        ->name('invoice');
});
