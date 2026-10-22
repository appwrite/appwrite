<?php

namespace Appwrite\Auth\Validator\Hash;

use Appwrite\Auth\Validator\Password;

/**
 * Validates an imported PHPass hash (portable or bcrypt) and caps its iteration count, which is honoured on every verify.
 */
class PHPass extends Password
{
    public const int ITERATIONS_MIN = 7;

    public const int ITERATIONS_MAX = 16;

    private const string ALPHABET = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const array PREFIXES = ['$P$', '$H$'];

    private const int LENGTH = 34;

    public function getDescription(): string
    {
        return 'Password must be a PHPass portable hash with an iteration count of at most 2^' . self::ITERATIONS_MAX . ', or a bcrypt hash with a cost of at most ' . Bcrypt::COST_MAX . '.';
    }

    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if (\str_starts_with($value, '$2')) {
            return (new Bcrypt())->isValid($value);
        }

        if (\strlen($value) !== self::LENGTH || !\in_array(\substr($value, 0, 3), self::PREFIXES, true)) {
            return false;
        }

        $iterations = \strpos(self::ALPHABET, $value[3]);

        return $iterations !== false && $iterations >= self::ITERATIONS_MIN && $iterations <= self::ITERATIONS_MAX;
    }
}
