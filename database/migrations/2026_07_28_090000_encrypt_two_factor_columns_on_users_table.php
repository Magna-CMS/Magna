<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill for the `encrypted` / `encrypted:array` casts added to
 * User::casts() — `two_factor_secret` and `two_factor_recovery_codes` were
 * previously stored as plaintext, so existing rows must be re-written through
 * the cipher or they will fail to decrypt on the next read.
 *
 * Both columns are widened first: ciphertext is substantially longer than the
 * plaintext it replaces, and a 32-char base32 secret encrypts to several
 * hundred bytes.
 *
 * Idempotent by construction — a value that already decrypts is left alone, so
 * re-running (or running against a partially migrated table) is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->change();
            $table->text('two_factor_recovery_codes')->nullable()->change();
        });

        DB::table('users')
            ->select(['id', 'two_factor_secret', 'two_factor_recovery_codes'])
            ->whereNotNull('two_factor_secret')
            ->orWhereNotNull('two_factor_recovery_codes')
            ->orderBy('id')
            ->chunk(200, function ($users): void {
                foreach ($users as $user) {
                    $update = [];

                    if (is_string($user->two_factor_secret) && ! $this->isEncrypted($user->two_factor_secret)) {
                        $update['two_factor_secret'] = Crypt::encryptString($user->two_factor_secret);
                    }

                    if (is_string($user->two_factor_recovery_codes) && ! $this->isEncrypted($user->two_factor_recovery_codes)) {
                        // Stored as a JSON string before; the array cast expects
                        // encrypt(json), so re-encode through the same shape.
                        $codes = json_decode($user->two_factor_recovery_codes, true);
                        $update['two_factor_recovery_codes'] = Crypt::encryptString(
                            (string) json_encode(is_array($codes) ? array_values($codes) : []),
                        );
                    }

                    if ($update !== []) {
                        DB::table('users')->where('id', $user->id)->update($update);
                    }
                }
            });
    }

    /**
     * Reverting leaves the values encrypted rather than writing secrets back
     * out in plaintext — rolling a schema change back is not a reason to
     * downgrade the protection on live credentials. Re-enrol affected users
     * instead if the casts are genuinely being removed.
     */
    public function down(): void
    {
        // Intentionally no data change.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
