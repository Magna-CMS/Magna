<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Magna\Frontend\Forms\SignedFormTimestamp;
use Tests\TestCase;

/*
 * A form on a cached page carried the CSRF token of whichever visitor's
 * render was cached: every later guest posted a token their own session
 * never issued. The page now renders an empty field and fills it from
 * /_magna/form-token, each visitor's own.
 */

uses(TestCase::class, RefreshDatabase::class);

/** The real CSRF check — the framework skips its own under unit tests. */
final class FormTokenStrictCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    Route::middleware(['web', FormTokenStrictCsrf::class])
        ->post('/_test/strict-csrf', fn () => 'accepted');
});

it('hands out this visitor\'s token and forbids storing it', function (): void {
    $response = $this->getJson('/_magna/form-token')->assertOk();

    expect($response->json('token'))->toBe(app('session.store')->token())
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('never becomes the page a failed post is sent back to', function (): void {
    $this->get('/_magna/form-token', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk();

    expect(app('session.store')->previousUrl())->toBeNull();

    $html = Blade::render('<form><x-magna::form-token/></form>');
    expect($html)->toContain("'X-Requested-With': 'XMLHttpRequest'");
});

it('issues a token the real csrf check accepts', function (): void {
    $token = $this->getJson('/_magna/form-token')->json('token');
    $session = app('session.store')->getId();

    $this->withCookie(config('session.cookie'), $session)
        ->post('/_test/strict-csrf', ['_token' => $token])
        ->assertOk()
        ->assertSee('accepted');
});

it('still refuses a post with a token this session never issued', function (): void {
    $this->getJson('/_magna/form-token');
    $session = app('session.store')->getId();

    $this->withCookie(config('session.cookie'), $session)
        ->post('/_test/strict-csrf', ['_token' => 'someone-elses-token'])
        ->assertStatus(419);
});

it('renders an empty token field, never the rendering visitor\'s token', function (): void {
    app('session.store')->start();
    $html = Blade::render('<form><x-magna::form-token/></form><form><x-magna::form-token/></form>');

    expect(app('session.store')->token())->toBeString()
        ->and($html)->toContain('<input type="hidden" name="_token" value="" data-magna-form-token>')
        ->and($html)->toContain('_magna\/form-token')
        ->and(substr_count($html, '<script>'))->toBe(1)
        ->and($html)->not->toContain(app('session.store')->token());
});

it('accepts its own signed stamp once a person could have typed', function (): void {
    $this->freezeTime();
    $stamp = SignedFormTimestamp::issue('magna-forms');

    expect(SignedFormTimestamp::passes($stamp, 'magna-forms', 2))->toBeFalse();

    $this->travel(3)->seconds();

    expect(SignedFormTimestamp::passes($stamp, 'magna-forms', 2))->toBeTrue()
        ->and(SignedFormTimestamp::passes($stamp, 'blog-comments', 2))->toBeFalse();
});

it('rejects a stamp that is forged, mangled or missing', function (mixed $stamp): void {
    $this->travel(1)->hours();

    expect(SignedFormTimestamp::passes($stamp, 'magna-forms', 2))->toBeFalse();
})->with([
    'missing' => null,
    'no signature' => '1700000000',
    'bad signature' => '1700000000.deadbeef',
    'not digits' => 'abc.def',
    'array' => [['x']],
]);

it('keeps passing a stamp a cached page has carried for days', function (): void {
    $stamp = SignedFormTimestamp::issue('magna-forms');
    $this->travel(9)->days();

    expect(SignedFormTimestamp::passes($stamp, 'magna-forms', 2))->toBeTrue();
});

it('still verifies a stamp the Forms plugin issued before the move into core', function (): void {
    $issuedAt = now()->subMinute()->getTimestamp();
    $legacy = $issuedAt.'.'.hash_hmac('sha256', 'magna-forms:'.$issuedAt, (string) config('app.key'));

    expect(SignedFormTimestamp::passes($legacy, 'magna-forms', 2))->toBeTrue();
});
