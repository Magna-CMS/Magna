<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Marketplace\Marketplace;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();

    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->accountName = 'Buyer';
    $settings->accountEmail = 'buyer@example.com';
    $settings->token = 'site-token';
    $settings->save();
});

function checkoutAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('dispatches the widget payload with a subscription id when the buyer opts into auto-renew', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/checkout/acme/annual' => Http::response([
            'ok' => true,
            'subscription' => [
                'id' => 7,
                'gateway_subscription_id' => 'sub_rzp_7',
                'amount' => 49900,
                'currency' => 'INR',
            ],
            'key_id' => 'rzp_test_key',
            'product' => ['package' => 'acme/annual', 'name' => 'Annual'],
            'buyer' => ['name' => 'Buyer', 'email' => 'buyer@example.com'],
            'refund_terms' => 'Full refund within 14 days.',
        ]),
        Marketplace::API_BASE.'/*' => Http::response([]),
    ]);
    $this->actingAs(checkoutAdmin());

    Livewire::test(PluginsPage::class)
        ->call('buy', 'acme/annual', 'annual', true)
        ->assertSet('pendingSubscriptionId', 7)
        ->assertSet('pendingOrderId', null)
        ->assertSet('pendingRefundTerms', 'Full refund within 14 days.')
        ->assertDispatched(
            'magna-checkout',
            fn (string $name, array $params): bool => ($params['payload']['gateway_subscription_id'] ?? null) === 'sub_rzp_7'
                && ! isset($params['payload']['gateway_order_id']),
        );
});

it('offers cancel auto-renew for a gateway subscription in the account centre', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/account/licenses' => Http::response(['licenses' => [[
            'id' => 11,
            'product_slug' => 'acme/annual',
            'licensable_type' => 'plugin',
            'license_type' => 'annual',
            'status' => 'active',
            'key' => 'MGNA-TEST-KEY',
            'license_expires_at' => now()->addMonths(11)->toIso8601String(),
            'activation_limit' => 1,
            'active_on_this_site' => false,
            'activations' => [],
            'subscription' => [
                'id' => 7,
                'status' => 'active',
                'collection_method' => 'gateway',
                'current_period_end' => now()->addMonths(11)->toIso8601String(),
                'cancelled_at' => null,
            ],
        ]]]),
        Marketplace::API_BASE.'/*' => Http::response([]),
    ]);
    $this->actingAs(checkoutAdmin());

    Livewire::test(AccountCentrePage::class)
        ->assertSee('Auto-renews')
        ->assertSee('Cancel auto-renew')
        ->assertDontSee('>Renew<', false);
});
