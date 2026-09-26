<?php

declare(strict_types=1);

use Magna\Updater\Engine\EngineLoader;

/**
 * The engine is new code run by the old updater. Before it is required, its
 * source is refused if it declares anything, names a Magna class, or
 * reaches past its context.
 */
it('accepts the engine that ships', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/bootstrap/update/engine.php');

    expect(EngineLoader::refuse($source))->toBeNull();
});

it('accepts a plain closure that only talks to its context', function (): void {
    $source = "<?php\nreturn static function (object \$ctx): void {\n    \$ctx->stage('src/Magna');\n    \$ctx->remove('app/Legacy');\n    \$x = \\RuntimeException::class;\n};\n";

    expect(EngineLoader::refuse($source))->toBeNull();
});

it('refuses declarations', function (string $source, string $reason): void {
    expect(EngineLoader::refuse($source))->toContain($reason);
})->with([
    'a class' => ["<?php\nclass Engine {}\nreturn static fn (object \$ctx) => null;", 'declares a class'],
    'an interface' => ["<?php\ninterface Engine {}\n", 'declares a type'],
    'an enum' => ["<?php\nenum Step { case One; }\n", 'declares a type'],
    'a named function' => ["<?php\nfunction helper(): void {}\nreturn static fn (object \$ctx) => null;", 'declares a named function'],
    'a namespace' => ["<?php\nnamespace Magna\\Engine;\nreturn static fn (object \$ctx) => null;", 'declares a namespace'],
]);

it('refuses reaching past the context', function (string $source, string $reason): void {
    expect(EngineLoader::refuse($source))->toContain($reason);
})->with([
    'a Magna class' => ["<?php\nreturn static function (object \$ctx): void { \\Magna\\Updater\\CoreUpdater::coreOwnedPaths(); };", 'names a Magna class'],
    'a Magna class without a leading slash' => ["<?php\nreturn static function (object \$ctx): void { Magna\\Support\\Runtime::isOctane(); };", 'names a Magna class'],
    'unlink' => ["<?php\nreturn static function (object \$ctx): void { unlink('/etc/passwd'); };", 'calls unlink()'],
    'rename' => ["<?php\nreturn static function (object \$ctx): void { rename('a', 'b'); };", 'calls rename()'],
    'a shell' => ["<?php\nreturn static function (object \$ctx): void { shell_exec('rm -rf /'); };", 'calls shell_exec()'],
    'backticks' => ["<?php\nreturn static function (object \$ctx): void { \$out = `ls`; };", 'shell command'],
    'an include' => ["<?php\nreturn static function (object \$ctx): void { require '/tmp/x.php'; };", 'includes another file'],
    'eval' => ["<?php\nreturn static function (object \$ctx): void { eval('1;'); };", 'uses eval'],
]);

it('tells a context method from the function of the same name', function (): void {
    expect(EngineLoader::refuse("<?php\nreturn static function (object \$ctx): void { \$ctx->rename('a'); };"))->toBeNull()
        ->and(EngineLoader::refuse("<?php\nreturn static function (object \$ctx): void { rename('a', 'b'); };"))->toContain('rename()');
});
