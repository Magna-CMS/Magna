<?php

declare(strict_types=1);

namespace Magna\Blocks\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;

/**
 * Laravel validation rule wiring block-document validation into every entry
 * save path (EntryManager create/update run field rules through
 * SchemaValidator, which consumes this from BlocksField::validationRules()).
 *
 * Closes the gap where PageTreeValidator only ran on the preview endpoint —
 * a malformed or unauthorized document could be persisted directly through
 * the management API or admin editor.
 *
 * Runs the structural validator always, and the actor authorization pass
 * against the currently authenticated user when one exists. No user means a
 * system context (CLI, seeding, imports) and skips authorization by policy —
 * see {@see PageTreeAuthorizer}.
 */
final class ValidBlockDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return; // the accompanying 'array' rule reports this
        }

        /** @var PageTreeValidator $validator */
        $validator = app(PageTreeValidator::class);
        /** @var PageTreeAuthorizer $authorizer */
        $authorizer = app(PageTreeAuthorizer::class);

        $errors = $validator->validate($value);

        $actor = auth()->user();
        if ($actor !== null) {
            $errors = [...$errors, ...$authorizer->authorize($value, $actor)];
        }

        foreach ($errors as $error) {
            $fail($error);
        }
    }
}
