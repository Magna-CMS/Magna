<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

// Verifies magna:plugin:doctor accurately detects an unapplied plugin migration
// by reading Laravel's migration repository (no guessing).

it('warns about an unapplied plugin migration', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');
    $this->enablePlugin('magna-cms/docs');

    // Simulate drift: a migration file exists on disk but its repository row is
    // gone (as if a new migration was added after the plugin was enabled).
    $removed = DB::table('migrations')->where('migration', 'like', '%docs%')->first();
    expect($removed)->not->toBeNull();
    DB::table('migrations')->where('id', $removed->id)->delete();

    $this->artisan('magna:plugin:doctor')
        ->expectsOutputToContain('has not been applied')
        ->assertSuccessful(); // warnings do not fail the command
});

it('reports a clean bill of health when everything is applied', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');
    $this->enablePlugin('magna-cms/docs');

    $this->artisan('magna:plugin:doctor')->assertSuccessful();
});
