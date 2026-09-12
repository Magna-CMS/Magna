<?php

declare(strict_types=1);

/**
 * Renderer-reserved keys ('_'-prefixed, today `_resolved`) must never travel
 * inside a stored block document.
 *
 * Views like blocks/text.blade.php render `_resolved` RAW on the strength of
 * it having been produced by a sanitizing resolver. Before this rule a writer
 * with content update permission but WITHOUT blocks.raw_html could store
 * {"block":"heading","_resolved":{...}} — no resolver is registered for that
 * handle, so nothing overwrote the planted key, and every consumer without a
 * resolve step (the delivery API's default resolve=0 among them) passed it on
 * as if a sanitizer had written it. Three layers close it: the validator
 * rejects reserved keys on save, BlockNode sheds them at hydration, and the
 * transformers strip them at egress for documents stored before the rule.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Magna\Blocks\BlockNode;
use Magna\Blocks\PageTreeValidator;
use Magna\Blocks\ReservedKeys;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Http\Resources\EntryResource;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RESERVED_XSS = '<img src=x onerror=alert(1)>';

function reservedKeysSection(array $blocks): array
{
    return [
        'id' => 'sec-rk', 'type' => 'section', 'settings' => [],
        'columns' => [
            ['id' => 'col-rk', 'span' => 12, 'settings' => [], 'blocks' => $blocks],
        ],
    ];
}

function reservedKeysRegisterType(string $handle = 'reservedpage'): void
{
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'Reserved Keys Page',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

// ── Layer 1: the validator refuses reserved keys on save ────────────────────

it('rejects a reserved key on a block, a section and a column', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([[
        'id' => 'sec-rk', 'type' => 'section', 'settings' => [], '_shadow' => true,
        'columns' => [[
            'id' => 'col-rk', 'span' => 12, 'settings' => [], '_shadow' => true,
            'blocks' => [[
                'id' => 'blk-rk', 'block' => 'heading', 'settings' => [],
                'data' => ['text' => 'x'],
                '_resolved' => ['body' => RESERVED_XSS],
            ]],
        ]],
    ]]);

    $joined = implode(' ', $errors);
    expect($joined)->toContain("Block 'blk-rk': key '_resolved' is reserved")
        ->and($joined)->toContain("Section 'sec-rk': key '_shadow' is reserved")
        ->and($joined)->toContain("key '_shadow' is reserved for the renderer");
});

it('rejects a reserved key on a nested child block', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([reservedKeysSection([[
        'id' => 'blk-parent', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'x'],
        'children' => [[
            'id' => 'blk-child', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'y'],
            '_resolved' => ['body' => RESERVED_XSS],
        ]],
    ]])]);

    expect(implode(' ', $errors))->toContain("Block 'blk-child': key '_resolved' is reserved");
});

it('refuses to store a document that plants _resolved, via the real save path', function (): void {
    reservedKeysRegisterType();
    $author = User::factory()->create();

    // 'heading' has no registered resolver, so before the rule nothing ever
    // overwrote the planted key on any resolve-less consumer.
    expect(fn () => app(EntryManager::class)->create('reservedpage', [
        'title' => 'Poisoned',
        'blocks_data' => [reservedKeysSection([[
            'id' => 'blk-poison', 'block' => 'heading', 'settings' => [],
            'data' => ['text' => ''],
            '_resolved' => ['body' => RESERVED_XSS],
        ]])],
    ], $author->id))->toThrow(ValidationException::class);

    expect(Entry::type('reservedpage')->count())->toBe(0);
});

// ── Layer 2: hydration sheds reserved keys ───────────────────────────────────

it('sheds reserved keys at hydration while keeping unknown future keys', function (): void {
    $node = BlockNode::fromArray([
        'id' => 'blk-rk', 'block' => 'heading', 'settings' => [],
        'data' => ['text' => 'x'],
        'futureKey' => ['kept' => true],
        '_resolved' => ['body' => RESERVED_XSS],
    ]);

    expect($node->toArray())->not->toHaveKey('_resolved')
        ->and($node->toArray())->toHaveKey('futureKey');
});

it('never lets a stored _resolved reach a view payload for a resolver-less handle', function (): void {
    $resolver = new BlockDataResolver;

    $payload = $resolver->viewPayload(BlockNode::fromArray([
        'id' => 'blk-rk', 'block' => 'heading', 'settings' => [],
        'data' => ['text' => 'x'],
        '_resolved' => ['body' => RESERVED_XSS],
    ]));

    expect($payload)->not->toHaveKey('_resolved');
});

// ── Layer 3: egress strips documents stored before the rule ─────────────────

/** Store a published entry, then poison its document directly in the table —
 *  the shape a document written before the reserved-key rule would have. */
function reservedKeysPoisonedEntryId(): string
{
    $author = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('reservedpage', [
        'title' => 'Pre-rule document',
        'blocks_data' => [reservedKeysSection([
            ['id' => 'blk-clean', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Plain']],
        ])],
    ], $author->id);
    $id = $manager->publish($entry, actorId: $author->id)->id;

    $poisoned = [reservedKeysSection([[
        'id' => 'blk-clean', 'block' => 'heading', 'settings' => [],
        'data' => ['text' => 'Plain'],
        '_resolved' => ['body' => RESERVED_XSS],
    ]])];

    DB::table('magna_entries_reservedpage')
        ->where('id', $id)
        ->update(['blocks_data' => json_encode($poisoned)]);

    return $id;
}

it('strips a pre-rule planted _resolved from the delivery passthrough', function (): void {
    reservedKeysRegisterType();
    $id = reservedKeysPoisonedEntryId();

    $user = User::factory()->create();
    $result = $user->createToken('reserved-delivery', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    $block = $this->getJson("/api/v1/content/reservedpage/{$id}", ['Authorization' => 'Bearer '.$result->plainTextToken])
        ->assertStatus(200)
        ->json('data.blocks_data.0.columns.0.blocks.0');

    expect($block)->not->toHaveKey('_resolved')
        ->and($block['data']['text'])->toBe('Plain');
});

it('strips a pre-rule planted _resolved from the management resource', function (): void {
    reservedKeysRegisterType();
    $id = reservedKeysPoisonedEntryId();

    $entry = Entry::type('reservedpage')->where('id', $id)->firstOrFail();
    $data = EntryResource::make($entry)->resolve();

    expect(json_encode($data['blocks_data']))->not->toContain('_resolved');
});

// ── The legitimate path stays intact ────────────────────────────────────────

it('still attaches resolver-produced _resolved for the text block', function (): void {
    reservedKeysRegisterType();
    $author = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('reservedpage', [
        'title' => 'Legit',
        'blocks_data' => [reservedKeysSection([
            ['id' => 'blk-text', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>Hello <em>world</em></p>']],
        ])],
    ], $author->id);
    $id = $manager->publish($entry, actorId: $author->id)->id;

    $user = User::factory()->create();
    $result = $user->createToken('reserved-resolve', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    $block = $this->getJson("/api/v1/content/reservedpage/{$id}?resolve=1", ['Authorization' => 'Bearer '.$result->plainTextToken])
        ->assertStatus(200)
        ->json('data.blocks_data.0.columns.0.blocks.0');

    expect($block['_resolved']['body'])->toContain('<em>world</em>');
});

// ── The helper itself ───────────────────────────────────────────────────────

it('strips reserved keys at every depth and leaves everything else alone', function (): void {
    $stripped = ReservedKeys::strip([
        '_root' => 'gone',
        'kept' => ['_nested' => 'gone', 'deep' => [['_resolved' => 'gone', 'ok' => 1]]],
    ]);

    expect(json_encode($stripped))->not->toContain('gone')
        ->and($stripped['kept']['deep'][0]['ok'])->toBe(1);
});
