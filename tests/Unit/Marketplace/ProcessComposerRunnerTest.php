<?php

declare(strict_types=1);

use Magna\Marketplace\ProcessComposerRunner;

/**
 * Reads the environment the runner would hand Composer. Running the real
 * binary here would prove nothing: the developer's shell has HOME set, which
 * is exactly the condition the production failure did not have.
 *
 * @return array<string, string|false>
 */
function composerEnvironmentFor(ProcessComposerRunner $runner): array
{
    /** @var array<string, string|false> $environment */
    $environment = (new ReflectionMethod($runner, 'environment'))->invoke($runner);

    return $environment;
}

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/magna-composer-home-'.bin2hex(random_bytes(6));
    $this->runner = new ProcessComposerRunner(sys_get_temp_dir(), $this->home);

    $this->originalHome = getenv('HOME');
    $this->originalComposerHome = getenv('COMPOSER_HOME');
});

afterEach(function (): void {
    foreach (['HOME' => $this->originalHome, 'COMPOSER_HOME' => $this->originalComposerHome] as $name => $value) {
        is_string($value) ? putenv("{$name}={$value}") : putenv($name);
    }

    if (is_dir($this->home)) {
        rmdir($this->home);
    }
});

// PHP-FPM runs with neither variable set, and Composer aborts on that with
// "The HOME or COMPOSER_HOME environment variable must be set for composer to
// run correctly" — so every install from the admin panel failed while the same
// command worked over SSH.
it('supplies a writable COMPOSER_HOME when the SAPI has none', function (): void {
    putenv('HOME');
    putenv('COMPOSER_HOME');

    $environment = composerEnvironmentFor($this->runner);

    expect($environment['COMPOSER_HOME'])->toBe($this->home)
        ->and(is_dir($this->home))->toBeTrue()
        ->and(is_writable($this->home))->toBeTrue();
});

it('leaves an existing COMPOSER_HOME alone', function (): void {
    putenv('HOME');
    putenv('COMPOSER_HOME=/opt/composer');

    expect(composerEnvironmentFor($this->runner)['COMPOSER_HOME'])->toBe('/opt/composer')
        ->and(is_dir($this->home))->toBeFalse();
});

// HOME alone is enough for Composer, so overriding it would move an existing
// install's package cache for no reason.
it('does not override a plain HOME', function (): void {
    putenv('HOME=/home/deploy');
    putenv('COMPOSER_HOME');

    $environment = composerEnvironmentFor($this->runner);

    expect($environment)->not->toHaveKey('COMPOSER_HOME')
        ->and($environment['HOME'])->toBe('/home/deploy');
});

it('always disables interactive prompts', function (): void {
    expect(composerEnvironmentFor($this->runner)['COMPOSER_NO_INTERACTION'])->toBe('1');
});

// Composer reports an unwritable home as a cache warning and then fails
// somewhere that looks unrelated, which is how a permissions problem gets
// misread as a broken plugin.
it('refuses to run rather than let Composer fail obscurely on an unwritable home', function (): void {
    putenv('HOME');
    putenv('COMPOSER_HOME');

    // A regular file where the directory should be: mkdir cannot replace it,
    // so this reproduces "cannot create the home" without needing chmod, which
    // does nothing for an administrator on Windows.
    file_put_contents($this->home, '');

    $result = (new ProcessComposerRunner(sys_get_temp_dir(), $this->home))->run(['--version']);

    expect($result->successful())->toBeFalse()
        ->and($result->output)->toContain('Composer needs a writable home directory');

    unlink($this->home);
})->skip(
    // run() resolves the binary before it checks the home, so with no Composer
    // on the machine this would assert against "Composer was not found".
    fn (): bool => ! (new ProcessComposerRunner(sys_get_temp_dir(), sys_get_temp_dir()))->isAvailable(),
    'Composer is not installed on this machine.',
);
