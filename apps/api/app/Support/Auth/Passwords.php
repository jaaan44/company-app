<?php

namespace App\Support\Auth;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in passwords (Phase 36A, DEC-066 M-3/M-4/M-8).
 *
 * - A temporary password is generated here, shown once to the
 *   Administrator who asked for it, and stored only as a hash. It is
 *   never logged or audited.
 * - Every chosen password follows the same rules: at least 12
 *   characters, at most 72 bytes (bcrypt's limit), not the account's
 *   email address, and not the password it replaces.
 */
final class Passwords
{
    /** No 0/O, 1/l/I: easy to read out and type. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    public const MIN_LENGTH = 12;

    private function __construct() {}

    /**
     * Four groups of four characters, for example `Kp7w-x3Rt-...`:
     * about 92 bits of randomness.
     */
    public static function temporary(): string
    {
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }

    /**
     * Validation rules for a newly chosen password.
     *
     * @return list<mixed>
     */
    public static function rules(User $user): array
    {
        return [
            'required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed',
            function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value)) {
                    return;
                }
                if (strlen($value) > 72) {
                    $fail('Use at most 72 characters.');
                } elseif (mb_strtolower(trim($value)) === mb_strtolower($user->email)) {
                    $fail('Don\'t use your email address as your password.');
                } elseif (Hash::check($value, $user->password)) {
                    $fail('Choose a password different from your current one.');
                }
            },
        ];
    }

    /**
     * Ends every sign-in of this account: phone tokens and, with the
     * database session driver, web sessions.
     */
    public static function endSignIns(User $user, ?string $keepSessionId = null): void
    {
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => null])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }
}
