<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Verifies native Laravel hashes and legacy Java BCrypt $2a$ hashes.
 */
final class PasswordVerifier
{
    public static function check(string $plainText, string $hash): bool
    {
        // PHP's password_get_info may report imported $2a$ hashes as unknown,
        // causing Laravel's bcrypt hasher to throw before password_verify runs.
        if (preg_match('/^\$2a\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1) {
            return password_verify($plainText, $hash);
        }

        return Hash::check($plainText, $hash);
    }
}
