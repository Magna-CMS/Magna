<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Covers the developer CLI: validate (CI gate) and info (inspection). Runs
// against the real discovered plugin set (magna/docs is wired in this repo).

it('validates all discovered plugins successfully', function (): void {
    $this->artisan('magna:plugin:validate')->assertSuccessful();
});

it('emits a machine-readable json report', function (): void {
    $this->artisan('magna:plugin:validate', ['--json' => true])
        ->assertSuccessful();
});

it('fails when asked to validate an unknown plugin', function (): void {
    $this->artisan('magna:plugin:validate', ['name' => 'nobody/nothing'])
        ->assertFailed();
});

it('shows info for a discovered plugin', function (): void {
    skipWithoutDevPlugin('magna/docs');

    $this->artisan('magna:plugin:info', ['name' => 'magna/docs'])
        ->assertSuccessful();
});

it('fails info for an unknown plugin', function (): void {
    $this->artisan('magna:plugin:info', ['name' => 'nobody/nothing'])
        ->assertFailed();
});

it('doctor succeeds when nothing is enabled', function (): void {
    $this->artisan('magna:plugin:doctor')->assertSuccessful();
});

it('doctor fails for a plugin that is not enabled', function (): void {
    $this->artisan('magna:plugin:doctor', ['name' => 'nobody/nothing'])
        ->assertFailed();
});
