<?php

declare(strict_types=1);

namespace Magna\Updater\Engine;

use Closure;
use Magna\Updater\Manifest\ReleaseManifest;
use RuntimeException;

/**
 * Loads a release's engine from the verified archive, and only that.
 *
 * The engine is new code executed by the old updater, which is no more than
 * the overlay already did (the next request runs the new code regardless);
 * the checks here are against a build mistake or a mis-packaged archive, not
 * an attacker with the signing key. Three things are refused before the file
 * is required: a hash that differs from the manifest's, a file that declares
 * anything (a class or function would collide with, or shadow, the old
 * side's own), and a file that reaches past its context — a `Magna\` symbol,
 * an include, eval, a shell, or the filesystem directly. Everything the
 * engine legitimately needs comes through EngineContext.
 */
final class EngineLoader
{
    /** @var list<int> */
    public const SUPPORTED_APIS = [EngineContext::API];

    /** @var list<string> */
    private const REFUSED_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        'unlink', 'rmdir', 'rename', 'copy', 'file_put_contents', 'mkdir', 'chmod', 'chown', 'symlink', 'link', 'touch', 'fopen',
        'call_user_func', 'call_user_func_array',
    ];

    public function supports(ReleaseManifest $manifest): bool
    {
        return $manifest->hasEngine() && in_array($manifest->engineApi, self::SUPPORTED_APIS, true);
    }

    /**
     * @return Closure(object): void
     *
     * @throws RuntimeException when the engine cannot be trusted to run
     */
    public function load(string $extractRoot, ReleaseManifest $manifest): Closure
    {
        if (! $this->supports($manifest)) {
            throw new RuntimeException('The release wants an update engine this updater cannot run (API '.($manifest->engineApi ?? 'none').').');
        }

        $path = rtrim($extractRoot, '/\\').'/'.$manifest->enginePath;

        if (! is_file($path)) {
            throw new RuntimeException("The archive does not contain its engine at {$manifest->enginePath}.");
        }

        $actual = hash_file('sha256', $path);

        if (! is_string($actual) || ! hash_equals((string) $manifest->engineSha256, $actual)) {
            throw new RuntimeException('The engine in the archive does not match the hash its manifest states — refusing to run it.');
        }

        $refusal = self::refuse((string) file_get_contents($path));

        if ($refusal !== null) {
            throw new RuntimeException('The release engine is not acceptable: '.$refusal);
        }

        $engine = require $path;

        if (! $engine instanceof Closure) {
            throw new RuntimeException('The release engine did not return a closure.');
        }

        return $engine;
    }

    /**
     * Why this source must not be run as an engine, or null when it may.
     * Public so the builder and the architecture tests run the same check
     * over the engine that ships.
     */
    public static function refuse(string $source): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '`') {
                    return 'it runs a shell command.';
                }

                continue;
            }

            [$id, $text] = $token;

            $refused = match ($id) {
                T_INTERFACE, T_TRAIT, T_ENUM => 'it declares a type.',
                T_NAMESPACE => 'it declares a namespace.',
                T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE => 'it includes another file.',
                T_EVAL => 'it uses eval.',
                default => null,
            };

            if ($refused !== null) {
                return $refused;
            }

            if ($id === T_CLASS && ! self::isClassConstantAccess($tokens, $i)) {
                return 'it declares a class.';
            }

            if ($id === T_FUNCTION && self::declaresNamedFunction($tokens, $i)) {
                return 'it declares a named function.';
            }

            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($text, '\\');

                if (str_starts_with($name, 'Magna\\') || $name === 'Magna') {
                    return 'it names a Magna class.';
                }

                if (in_array(strtolower($name), self::REFUSED_FUNCTIONS, true) && ! self::isMethodName($tokens, $i)) {
                    return "it calls {$name}() instead of asking its context.";
                }
            }
        }

        return null;
    }

    /**
     * `Foo::class` is a constant fetch, not a declaration.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function isClassConstantAccess(array $tokens, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($previous) && $previous[0] === T_DOUBLE_COLON;
        }

        return false;
    }

    /**
     * `function name(` declares; `function (` and `fn (` are closures.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function declaresNamedFunction(array $tokens, int $index): bool
    {
        $count = count($tokens);

        for ($j = $index + 1; $j < $count; $j++) {
            $next = $tokens[$j];

            if (is_string($next)) {
                return $next !== '(' && $next !== '&';
            }

            if (in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $next[0] === T_STRING;
        }

        return false;
    }

    /**
     * `$ctx->remove(` is the context's method; `remove(` bare is the function.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function isMethodName(array $tokens, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        }

        return false;
    }
}
