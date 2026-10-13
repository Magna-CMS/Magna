<?php

declare(strict_types=1);

/**
 * Forms travel in the site kit ('forms:forms').
 *
 * The form block selects a form by handle, so a promoted site has to carry
 * the forms its pages reference — the Lekha contact page applied cleanly and
 * rendered a hole until an admin hand-created `lekha-contact`, with a field
 * table in the theme README standing in for what the kit should have done.
 */

use Magna\Forms\Blocks\FormBlockResolver;
use Magna\Forms\Models\Form;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

function formsKitUser(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    skipWithoutDevPlugin('magna-cms/forms');
    app(PluginManager::class)->enable('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/forms');
}

function kitContactForm(): Form
{
    return Form::query()->create([
        'handle' => 'lekha-contact',
        'name' => 'Contact',
        'fields' => [
            ['handle' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true],
            ['handle' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['handle' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
        ],
        'notify_emails' => ['hello@example.com'],
    ]);
}

it('exports the site\'s forms under forms:forms', function (): void {
    formsKitUser();
    kitContactForm();

    $kit = app(SiteKit::class)->build();

    expect($kit['forms:forms'])->toHaveCount(1)
        ->and($kit['forms:forms'][0]['handle'])->toBe('lekha-contact')
        ->and($kit['forms:forms'][0]['fields'])->toHaveCount(3);
});

it('seeds a form the kit brought, and the form block then resolves it', function (): void {
    formsKitUser();
    kitContactForm();

    $bundle = app(SiteKit::class)->build();
    Form::query()->delete(); // the target environment has no forms yet

    $counts = app(SiteKit::class)->apply($bundle);

    expect($counts['forms:forms'])->toBe(1);

    // The point of the whole exercise: the form block's select resolves by
    // handle, so the applied kit leaves no invisible hole on the page.
    $resolved = app(FormBlockResolver::class)->resolve(['form' => 'lekha-contact']);

    expect($resolved)->not->toBe([])
        ->and($resolved['name'])->toBe('Contact')
        ->and($resolved['fields'])->toHaveCount(3);
});

it('updates the form an environment already has rather than duplicating it', function (): void {
    formsKitUser();
    kitContactForm();

    $bundle = app(SiteKit::class)->build();

    Form::query()->where('handle', 'lekha-contact')->update(['name' => 'Old name']);
    app(SiteKit::class)->apply($bundle);

    expect(Form::query()->where('handle', 'lekha-contact')->count())->toBe(1)
        ->and(Form::query()->where('handle', 'lekha-contact')->value('name'))->toBe('Contact');
});

it('skips a malformed form row and applies the rest', function (): void {
    formsKitUser();

    $bundle = app(SiteKit::class)->build();
    $bundle['forms:forms'] = [
        ['name' => 'No handle at all'],
        ['handle' => 'good', 'name' => 'Good', 'fields' => [
            ['handle' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ]],
    ];

    $counts = app(SiteKit::class)->apply($bundle);

    expect($counts['forms:forms'])->toBe(1)
        ->and(Form::query()->where('handle', 'good')->exists())->toBeTrue();
});

it('round-trips without reporting a phantom change', function (): void {
    formsKitUser();
    kitContactForm();

    $bundle = app(SiteKit::class)->build();

    $diff = app(SiteKit::class)->diff($bundle);

    expect($diff['forms:forms'])->toBe(['added' => [], 'changed' => [], 'localOnly' => []]);
});
