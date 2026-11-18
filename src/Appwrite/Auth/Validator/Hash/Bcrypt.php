<?php

namespace Appwrite\Auth\Validator\Hash;

use Appwrite\Auth\Validator\Password;

/**
 * Validates an imported bcrypt hash and caps its cost, which is honoured on every verify.
 */
class Bcrypt extends Password
{
    public const int COST_MIN = 4;

    public const int COST_MAX = 15;

    private const string ALPHABET = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const array PREFIXES = ['$2a$', '$2b$', '$2x$', '$2y$'];

    private const int LENGTH = 60;

    public function getDescription(): string
    {
        return 'Password must be a bcrypt hash with a cost of at most ' . self::COST_MAX . '.';
    }

    public function isValid($value): bool
    {
        if (!parent::isValid($value) || \strlen($value) !== self::LENGTH) {
            return false;
        }

        if (!\in_array(\substr($value, 0, 4), self::PREFIXES, true) || $value[6] !== '$') {
            return false;
        }

        $cost = \substr($value, 4, 2);
        if (!\ctype_digit($cost)) {
            return false;
        }

        // A salt or digest outside the bcrypt alphabet makes crypt() fail, so the user could never sign in
        if (\strspn($value, self::ALPHABET, 7) !== self::LENGTH - 7) {
            return false;
        }

        return (int) $cost >= self::COST_MIN && (int) $cost <= self::COST_MAX;
    }
}
