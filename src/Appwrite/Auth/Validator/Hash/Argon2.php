<?php

namespace Appwrite\Auth\Validator\Hash;

use Appwrite\Auth\Validator\Password;

/**
 * Validates an imported Argon2 hash and caps its costs, which are honoured on every verify.
 */
class Argon2 extends Password
{
    public const int MEMORY_MAX = 262144;

    public const int TIME_MAX = 10;

    public const int THREADS_MAX = 16;

    private const array ALGORITHMS = ['argon2i', 'argon2id'];

    // Argon2 1.0 (0x10) and 1.3 (0x13)
    private const array VERSIONS = ['v=16', 'v=19'];

    // Salt and digest are unpadded base64
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    public function getDescription(): string
    {
        return 'Password must be an Argon2 hash with a memory cost of at most ' . self::MEMORY_MAX . ' KiB, a time cost of at most ' . self::TIME_MAX . ' and a parallelism of at most ' . self::THREADS_MAX . '.';
    }

    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        $parts = \explode('$', $value);
        if (\count($parts) !== 6 || $parts[0] !== '' || !\in_array($parts[1], self::ALGORITHMS, true) || !\in_array($parts[2], self::VERSIONS, true)) {
            return false;
        }

        foreach ([$parts[4], $parts[5]] as $encoded) {
            if ($encoded === '' || \strspn($encoded, self::ALPHABET) !== \strlen($encoded) || \strlen($encoded) % 4 === 1) {
                return false;
            }
        }

        $costs = \explode(',', $parts[3]);
        $limits = ['m=' => self::MEMORY_MAX, 't=' => self::TIME_MAX, 'p=' => self::THREADS_MAX];
        if (\count($costs) !== \count($limits)) {
            return false;
        }

        foreach (\array_keys($limits) as $index => $prefix) {
            if (!\str_starts_with($costs[$index], $prefix)) {
                return false;
            }

            $cost = \substr($costs[$index], \strlen($prefix));
            if (!\ctype_digit($cost) || (int) $cost < 1 || (int) $cost > $limits[$prefix]) {
                return false;
            }
        }

        // Argon2 needs at least 8 KiB of memory per lane, or the hash can never be verified
        $memory = (int) \substr($costs[0], 2);
        $threads = (int) \substr($costs[2], 2);

        return $memory >= 8 * $threads;
    }
}
