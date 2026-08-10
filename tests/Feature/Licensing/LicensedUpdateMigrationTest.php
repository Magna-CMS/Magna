<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginRecord;
use Symfony\Component\Filesystem\Filesystem;

uses(RefreshDatabase::class);

/**
 * Regression: a licensed plugin UPDATE never ran the plugin's migrations.
 *
 * enable() is the one place that runs them and registers a plugin's
 * permissions, and the installer called it only on a first install. An update
 * was therefore applied as files alone — new code against the old schema — and
 * the site broke on the next request that touched a table the new migration was
 * supposed to have created:
 *
 *     SQLSTATE[HY000]: General error: 1 no such table: roya_company_user_departments
 *
 * Nothing in the suite caught it because the existing tests stop at
 * authenticity, structure and discovery: none of them updated an installed
 * plugin whose package carried a migration.
 */
beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    $this->package = 'acme/licensed-widget';
    $this->target = base_path('plugins-dev/acme/licensed-widget');
    $this->table = 'acme_widget_things';

    $this->zipPath = tempnam(sys_get_temp_dir(), 'magna-licensed-update-').'.zip';

    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('magna.json', json_encode([
        'name' => $this->package,
        'displayName' => 'Licensed Widget',
        'description' => 'A fixture that exists only inside this test.',
        'version' => '2.0.0',
        'author' => 'Acme',
        'license' => 'proprietary',
        'entry' => 'Acme\\LicensedWidget\\WidgetPlugin',
        'compat' => ['magna' => MagnaServiceProvider::VERSION, 'php' => '^8.3'],
        'provides' => [],
        'permissions' => [],
    ]));

    // The whole point of the fixture: a package that carries schema. An update
    // that does not run this leaves the new code without its table.
    $zip->addFromString('database/migrations/2026_08_10_000000_create_acme_widget_things_table.php', <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acme_widget_things', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_widget_things');
    }
};
PHP);
    $zip->close();

    $this->zipBytes = (string) file_get_contents($this->zipPath);
    $this->zipSha = hash('sha256', $this->zipBytes);
});

afterEach(function (): void {
    Schema::dropIfExists($this->table);

    $files = new Filesystem;
    $files->remove([$this->zipPath, $this->target]);
    $files->remove(base_path('plugins-dev/acme'));
});

function updateGrant(string $sha, string $secret): array
{
    return [
        'url' => Marketplace::WEB_BASE.'/api/v1/license/download/nonce123?signature=abc',
        'expires_at' => now()->addMinutes(5)->toIso8601String(),
        'version' => '2.0.0',
        'sha256' => $sha,
        'sha256_signature' => base64_encode(sodium_crypto_sign_detached(
            SignedPayload::canonicalize(['sha256' => $sha]),
            $secret,
        )),
        'algorithm' => 'ed25519',
    ];
}

it('runs the migrations a licensed update brings with it', function (): void {
    Http::fake(['*' => Http::response($this->zipBytes)]);

    // What actually decides update-versus-install is the directory being there
    // (LicenseInstaller: $isUpdate = is_dir($targetDir)), not the record. Writing
    // only the record left this on the install path, where enable() runs anyway —
    // so the first version of this test passed against the bug it was written for.
    $files = new Filesystem;
    $files->mkdir($this->target);
    file_put_contents($this->target.'/magna.json', json_encode([
        'name' => $this->package,
        'version' => '1.0.0',
    ]));

    PluginRecord::query()->create([
        'name' => $this->package,
        'display_name' => 'Licensed Widget',
        'version' => '1.0.0',
        'base_path' => $this->target,
        'manifest' => ['name' => $this->package, 'version' => '1.0.0'],
        'enabled' => true,
        'requires_license' => true,
    ]);

    expect(Schema::hasTable($this->table))->toBeFalse();

    try {
        app(LicenseInstaller::class)
            ->installFromGrant($this->package, updateGrant($this->zipSha, $this->signingSecret));
    } catch (Throwable $e) {
        // enable() instantiates the entry class, which this fixture does not
        // ship, so it may well throw after migrating. The migration running is
        // the thing under test.
        expect($e->getMessage())->not->toContain('was not found');
    }

    expect(Schema::hasTable($this->table))->toBeTrue();
});
