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
    // Target a migration the docs PLUGIN actually owns — not merely any row
    // whose name contains "docs" (core carries a rename_docs_plugin_identity
    // migration that would otherwise be picked, and doctor only inspects the
    // plugin's own migration directory).
    $removed = DB::table('migrations')->where('migration', 'like', '%create_docs_pages_table%')->first();
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
