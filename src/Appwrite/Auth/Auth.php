<?php

namespace Appwrite\Auth;

use Appwrite\Auth\Algorithm\Bcrypt;
use Appwrite\Auth\Algorithm\MD5;
use Appwrite\Auth\Algorithm\PHPass;
use Appwrite\Auth\Algorithm\SHA;
use Appwrite\Auth\Algorithm\Scrypt;
use Appwrite\Auth\Algorithm\ScryptModified;
use Appwrite\Auth\Algorithm\Plain;
use Appwrite\Auth\Algorithm\Argon2;
use Appwrite\Auth\Algorithm\PBKDF2;
use Appwrite\Auth\Validator\Password;
use Appwrite\Utopia\Database\Document;

class Auth
{
    /**
     * List of all supported hashing algorithms.
     *
     * @var array<string, Algorithm>
     */
    public static array $algorithms = [];

    public const DEFAULT_ALGO = 'bcrypt';
    public const DEFAULT_ALGO_OPTIONS = [
        'cost' => 8,
    ];

    /**
     * Register available algorithms.
     *
     * @return void
     */
    public static function load(): void
    {
        self::$algorithms = [
            'bcrypt' => new Bcrypt(),
            'argon2' => new Argon2(),
            'md5' => new MD5(),
            'phpass' => new PHPass(),
            'sha' => new SHA(),
            'scrypt' => new Scrypt(),
            'scrypt-modified' => new ScryptModified(),
            'plain' => new Plain(),
            'pbkdf2' => new PBKDF2(),
        ];
    }

    /**
     * Hash a password.
     *
     * @param string $password
     * @param string $algo
     * @param array $options
     *
     * @return string
     */
    public static function passwordHash(string $password, string $algo = self::DEFAULT_ALGO, array $options = self::DEFAULT_ALGO_OPTIONS): string
    {
        return self::$algorithms[$algo]->hash($password, $options);
    }

    /**
     * Verify password hash.
     *
     * @param string $password
     * @param string $hash
     * @param string $algo
     * @param array $options
     *
     * @return bool
     */
    public static function passwordVerify(string $password, string $hash, string $algo = self::DEFAULT_ALGO, array $options = self::DEFAULT_ALGO_OPTIONS): bool
    {
        return self::$algorithms[$algo]->verify($password, $hash, $options);
    }

    /**
     * Get password validator.
     *
     * @param int $length
     *
     * @return Password
     */
    public static function passwordValidator(int $length = 6): Password
    {
        return new Password($length);
    }

    /**
     * Create a hashed password for new user accounts.
     *
     * @param string $password
     * @param string $algo
     * @param array $options
     *
     * @return string
     */
    public static function createPassword(string $password, string $algo = self::DEFAULT_ALGO, array $options = self::DEFAULT_ALGO_OPTIONS): string
    {
        return self::passwordHash($password, $algo, $options);
    }

    /**
     * Update Password with old password verification.
     *
     * @param Document $user The user document
     * @param string $oldPassword The old password entered by the user
     * @param string $newPassword The new password to set
     * @param string $algo Hashing algorithm
     * @param array $options Algorithm-specific options
     *
     * @return string The hashed new password
     *
     * @throws \Exception If old password is incorrect
     */
    public static function updatePassword(Document $user, string $oldPassword, string $newPassword, string $algo = self::DEFAULT_ALGO, array $options = self::DEFAULT_ALGO_OPTIONS): string
    {
        $hash = $user->getAttribute('password');
        $algoUsed = $user->getAttribute('hash', $algo);
        $optionsUsed = $user->getAttribute('hashOptions', $options);

        // ✅ Verify old password
        if (!self::passwordVerify($oldPassword, $hash, $algoUsed, $optionsUsed)) {
            throw new \Exception('Old password is incorrect');
        }

        // ✅ Return new hashed password
        return self::passwordHash($newPassword, $algo, $options);
    }
}
