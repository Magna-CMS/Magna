<?php

use Illuminate\Support\Facades\Route;
use Magna\Admin\Http\DebugModeController;

// Admin form posts that are deliberately NOT Livewire methods, because a
// Livewire method is reachable by anyone who can render its component.
Route::middleware(['web', 'auth'])->name('magna.admin.')->group(function (): void {
    Route::post('/system/debug-mode', DebugModeController::class)->name('debug-mode');
});
