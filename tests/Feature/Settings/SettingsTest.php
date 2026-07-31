<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Magna\Settings\GeneralSettings;
use Magna\Settings\MailSettings;

// Flush the settings cache before each test so stale cached values from other
// tests (which RefreshDatabase rolls back in the DB but NOT in memory) can't
// bleed through.
beforeEach(function (): void {
    Cache::flush();
});

it('returns class defaults when nothing is stored', function (): void {
    $settings = GeneralSettings::get();

    expect($settings->site_name)->toBe('Magna CMS');
    expect($settings->default_locale)->toBe('en');
    expect($settings->registration_enabled)->toBeFalse();
});

it('persists and retrieves a changed value', function (): void {
    $settings = GeneralSettings::get();
    $settings->site_name = 'Test Site';
    $settings->save();

    $loaded = GeneralSettings::get();

    expect($loaded->site_name)->toBe('Test Site');
});

it('encrypts secret properties at rest', function (): void {
    $settings = MailSettings::get();
    $settings->password = 'super-secret';
    $settings->save();

    // DB row should not contain the plaintext password.
    $row = DB::table('settings')
        ->where('group', 'mail')
        ->where('key', 'password')
        ->first();

    expect($row)->not->toBeNull();

    /** @var string $rawJson */
    $rawJson = $row->value; // @phpstan-ignore-line
    $stored = json_decode($rawJson, true);
    expect($stored)->not->toBe('super-secret');

    // But the model must decrypt it transparently on load.
    $loaded = MailSettings::get();
    expect($loaded->password)->toBe('super-secret');
});

it('masks secret properties in toArray', function (): void {
    $settings = MailSettings::get();
    $settings->password = 'super-secret';

    $masked = $settings->toArray(maskSecrets: true);
    expect($masked['password'])->toBe('[secret]');

    $plain = $settings->toArray();
    expect($plain['password'])->toBe('super-secret');
});

it('invalidates the cache on save so subsequent loads see fresh values', function (): void {
    $s1 = GeneralSettings::get();
    $s1->site_name = 'Original';
    $s1->save();

    // Confirm cached value is 'Original'.
    expect(GeneralSettings::get()->site_name)->toBe('Original');

    // Save new value — must bust the cache.
    $s2 = GeneralSettings::get();
    $s2->site_name = 'Updated';
    $s2->save();

    // Fresh load must return the updated value, not the stale cache.
    expect(GeneralSettings::get()->site_name)->toBe('Updated');
});
