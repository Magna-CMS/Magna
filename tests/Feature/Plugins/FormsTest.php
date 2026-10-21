<?php

declare(strict_types=1);

/**
 * magna-cms/forms v1: a form built in the admin, placed with the form block,
 * submitted by the public. The definition IS the validation — an
 * undeclared key can never be stored, a select may only submit what it
 * offers — and the spam traps stay quiet about tripping.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Forms\Filament\Pages\FormsPage;
use Magna\Forms\Filament\Pages\SubmissionsPage;
use Magna\Forms\FormSubmissionHandler;
use Magna\Forms\Models\Form;
use Magna\Forms\Models\Submission;
use Magna\Forms\Notifications\SubmissionMail;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

/** The real CSRF check — the framework skips its own under unit tests. */
final class FormsStrictCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

function formsSetup(): User
{
    skipWithoutDevPlugin('magna-cms/forms');
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/forms');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish', 'forms.manage', 'forms.submissions');
    $user->assignRole($role);

    return $user;
}

function contactForm(): Form
{
    return Form::query()->create([
        'handle' => 'contact',
        'name' => 'Contact',
        'fields' => [
            ['handle' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true],
            ['handle' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['handle' => 'topic', 'label' => 'Topic', 'type' => 'select', 'required' => false, 'options' => ['Sales', 'Support']],
            ['handle' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
        ],
        'notify_emails' => ['team@example.com'],
    ]);
}

function formPage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Contact us', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-form', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-form', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-form', 'block' => 'form', 'settings' => [], 'data' => [
                    'heading' => 'Say hello', 'form' => 'contact',
                ]]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

/**
 * A payload whose signed stamp is old enough to clear the timing trap
 * (issued "3 seconds ago" on the app clock).
 *
 * @return array<string, mixed>
 */
function validPayload(): array
{
    $token = Carbon::withTestNow(
        now()->subSeconds(3),
        fn (): string => FormSubmissionHandler::timestampToken(),
    );

    return [
        '_ts' => $token,
        'fields' => [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'topic' => 'Sales',
            'message' => 'Hello there',
        ],
    ];
}

it('renders the form on a page and accepts a valid submission', function (): void {
    $author = formsSetup();
    contactForm();
    formPage($author, 'contact-us');

    $html = $this->get('/contact-us')->assertOk()->getContent();
    expect($html)->toContain('Say hello')
        ->and($html)->toContain('name="fields[email]"')
        ->and($html)->toContain('action="'.url('/forms/contact').'"')
        // The honeypot ships, hidden.
        ->and($html)->toContain('name="'.FormSubmissionHandler::HONEYPOT.'"');

    Mail::fake();
    $this->travel(3)->seconds();

    $this->postJson('/forms/contact', validPayload())->assertOk()->assertJsonPath('ok', true);

    $submission = Submission::query()->firstOrFail();
    expect($submission->data)->toBe([
        'name' => 'Ada', 'email' => 'ada@example.com', 'topic' => 'Sales', 'message' => 'Hello there',
    ])
        // The IP is hashed, never stored raw.
        ->and($submission->ip_hash)->not->toBeNull()
        ->and($submission->ip_hash)->not->toContain('127.0.0.1');

    Mail::assertSent(SubmissionMail::class);
});

/*
 * Pages caches a guest's render and serves those bytes to every guest after
 * them. A form that baked @csrf into the page handed the first visitor's
 * token to everybody — a token no later session ever issued, so every later
 * post was a 419, and the first visitor's token was public. The form now
 * renders an empty field and each visitor fetches their own.
 */
it('submits from a cached page with the later visitor\'s own token', function (): void {
    $author = formsSetup();
    contactForm()->update(['success_message' => 'Got it, thanks.']);
    formPage($author, 'contact-us');
    Mail::fake();

    // Every web route — the forms endpoint included — runs the real check.
    $this->app->bind(PreventRequestForgery::class, FormsStrictCsrf::class);

    // First guest: the render Pages stores.
    $this->get('/contact-us')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    $firstToken = app('session.store')->token();
    $cached = (string) DB::table('pages_cache')->where('url', '/contact-us')->value('body');

    expect($firstToken)->toBeString()->not->toBe('')
        ->and($cached)->toContain('name="fields[email]"')
        ->and($cached)->toContain('<input type="hidden" name="_token" value="" data-magna-form-token>')
        ->and($cached)->not->toContain($firstToken);

    // Second guest: a session of their own (no cookie, nothing carried
    // over, so a fresh id and a fresh token), served the cached bytes.
    app('session.store')->flush();

    $html = (string) $this->get('/contact-us')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'hit')
        ->getContent();
    $session = app('session.store')->getId();
    $cookie = (string) config('session.cookie');

    // The stamp is the one the cached render carried.
    expect(preg_match('/name="_ts" value="([^"]+)"/', $html, $stamp))->toBe(1);

    $token = $this->withCookie($cookie, $session)
        ->getJson('/_magna/form-token')
        ->assertOk()
        ->json('token');

    expect($token)->toBeString()->not->toBe($firstToken);

    $this->travel(3)->seconds();
    $payload = ['_ts' => $stamp[1], 'fields' => validPayload()['fields']];

    // The first visitor's token, had it leaked, is still refused...
    $this->withCookie($cookie, $session)
        ->withHeader('Referer', url('/contact-us'))
        ->post('/forms/contact', [...$payload, '_token' => $firstToken])
        ->assertStatus(419);
    expect(Submission::query()->count())->toBe(0);

    // ...and this visitor's own one goes through.
    $this->withCookie($cookie, $session)
        ->withHeader('Referer', url('/contact-us'))
        ->post('/forms/contact', [...$payload, '_token' => $token])
        ->assertRedirect(url('/contact-us'))
        ->assertSessionHas('magna_form_sent.contact', 'Got it, thanks.');

    expect(Submission::query()->count())->toBe(1);
    Mail::assertSent(SubmissionMail::class);

    // Back on the page, the message is shown: a session carrying flash data
    // is never answered from the cache, which would show the bare form.
    $this->withCookie($cookie, $session)
        ->get('/contact-us')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'bypass')
        ->assertSee('Got it, thanks.');
});

it('validates from the definition and ignores undeclared keys', function (): void {
    formsSetup();
    contactForm();
    $this->travel(3)->seconds();

    // Missing required + bad email + a select value that is not offered.
    $this->postJson('/forms/contact', [
        ...validPayload(),
        'fields' => ['email' => 'not-an-email', 'topic' => 'Anything'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonStructure(['errors' => ['fields.name', 'fields.email', 'fields.topic', 'fields.message']]);

    // A key no field declares never reaches storage.
    $payload = validPayload();
    $payload['fields']['is_admin'] = 'yes';
    $this->postJson('/forms/contact', $payload)->assertOk();

    expect(array_keys(Submission::query()->firstOrFail()->data))
        ->toBe(['name', 'email', 'topic', 'message']);
});

it('swallows honeypot hits and instant posts without storing or telling', function (): void {
    formsSetup();
    contactForm();
    Mail::fake();

    // Honeypot filled: looks successful, stores nothing.
    $this->travel(3)->seconds();
    $payload = validPayload();
    $payload[FormSubmissionHandler::HONEYPOT] = 'http://spam.example';
    $this->postJson('/forms/contact', $payload)->assertOk()->assertJsonPath('ok', true);

    // Submitted the instant the page rendered: same quiet success.
    $this->postJson('/forms/contact', [
        ...validPayload(),
        '_ts' => FormSubmissionHandler::timestampToken(),
    ])->assertOk()->assertJsonPath('ok', true);

    // A forged timestamp is refused the same way.
    $this->postJson('/forms/contact', [...validPayload(), '_ts' => '1.deadbeef'])
        ->assertOk()->assertJsonPath('ok', true);

    expect(Submission::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('renders the Forms and Submissions admin pages', function (): void {
    $user = formsSetup();
    $form = contactForm();
    Submission::query()->create([
        'form_id' => $form->id,
        'data' => ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello'],
    ]);

    Filament::setCurrentPanel(Filament::getPanel('magna'));
    $this->actingAs($user);

    // Mounting selects the first form, so the editor panel renders too —
    // this compiles the whole view, fields grid included.
    Livewire::test(FormsPage::class)
        ->assertOk()
        ->assertSee('Contact')
        ->assertSee('1 submissions')
        ->assertSee('Save form');

    Livewire::test(SubmissionsPage::class)
        ->assertOk()
        ->assertSee('ada@example.com');
});

it('renders a gap when the chosen form was deleted', function (): void {
    $author = formsSetup();
    contactForm();
    formPage($author, 'gone-form');

    Form::query()->where('handle', 'contact')->delete();

    $html = $this->get('/gone-form')->assertOk()->getContent();
    expect($html)->not->toContain('magna-form')
        ->and($html)->not->toContain('Say hello');
});
