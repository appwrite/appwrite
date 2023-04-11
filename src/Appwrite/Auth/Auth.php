<?php

namespace Appwrite\Auth;

use Appwrite\Auth\Hash\Argon2;
use Appwrite\Auth\Hash\Bcrypt;
use Appwrite\Auth\Hash\Md5;
use Appwrite\Auth\Hash\Phpass;
use Appwrite\Auth\Hash\Scrypt;
use Appwrite\Auth\Hash\Scryptmodified;
use Appwrite\Auth\Hash\Sha;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;

class Auth
{
    public const SUPPORTED_ALGOS = [
        'argon2',
        'bcrypt',
        'md5',
        'sha',
        'phpass',
        'scrypt',
        'scryptMod',
        'plaintext',
    ];

    public const DEFAULT_ALGO = 'argon2';

    public const DEFAULT_ALGO_OPTIONS = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];

    /**
     * User Roles.
     */
    public const USER_ROLE_ANY = 'any';

    public const USER_ROLE_GUESTS = 'guests';

    public const USER_ROLE_USERS = 'users';

    public const USER_ROLE_ADMIN = 'admin';

    public const USER_ROLE_DEVELOPER = 'developer';

    public const USER_ROLE_OWNER = 'owner';

    public const USER_ROLE_APPS = 'apps';

    public const USER_ROLE_SYSTEM = 'system';

    /**
     * Token Types.
     */
    public const TOKEN_TYPE_LOGIN = 1; // Deprecated

    public const TOKEN_TYPE_VERIFICATION = 2;

    public const TOKEN_TYPE_RECOVERY = 3;

    public const TOKEN_TYPE_INVITE = 4;

    public const TOKEN_TYPE_MAGIC_URL = 5;

    public const TOKEN_TYPE_PHONE = 6;

    /**
     * Session Providers.
     */
    public const SESSION_PROVIDER_EMAIL = 'email';

    public const SESSION_PROVIDER_ANONYMOUS = 'anonymous';

    public const SESSION_PROVIDER_MAGIC_URL = 'magic-url';

    public const SESSION_PROVIDER_PHONE = 'phone';

    /**
     * Token Expiration times.
     */
    public const TOKEN_EXPIRATION_LOGIN_LONG = 31536000;      /* 1 year */

    public const TOKEN_EXPIRATION_LOGIN_SHORT = 3600;         /* 1 hour */

    public const TOKEN_EXPIRATION_RECOVERY = 3600;            /* 1 hour */

    public const TOKEN_EXPIRATION_CONFIRM = 3600 * 24 * 7;    /* 7 days */

    public const TOKEN_EXPIRATION_PHONE = 60 * 15;            /* 15 minutes */

    /**
     * @var string
     */
    public static $cookieName = 'a_session';

    /**
     * User Unique ID.
     *
     * @var string
     */
    public static $unique = '';

    /**
     * User Secret Key.
     *
     * @var string
     */
    public static $secret = '';

    /**
     * Set Cookie Name.
     *
     * @param $string
     * @return string
     */
    public static function setCookieName($string)
    {
        return self::$cookieName = $string;
    }

    /**
     * Encode Session.
     *
     * @param  string  $id
     * @param  string  $secret
     * @return string
     */
    public static function encodeSession($id, $secret)
    {
        return \base64_encode(\json_encode([
            'id' => $id,
            'secret' => $secret,
        ]));
    }

    /**
     * Decode Session.
     *
     * @param  string  $session
     * @return array
     *
     * @throws \Exception
     */
    public static function decodeSession($session)
    {
        $session = \json_decode(\base64_decode($session), true);
        $default = ['id' => null, 'secret' => ''];

        if (! \is_array($session)) {
            return $default;
        }

        return \array_merge($default, $session);
    }

    /**
     * Encode.
     *
     * One-way encryption
     *
     * @param $string
     * @return string
     */
    public static function hash(string $string)
    {
        return \hash('sha256', $string);
    }

    /**
     * Password Hash.
     *
     * One way string hashing for user passwords
     *
     * @param  string  $string
     * @param  string  $algo hashing algorithm to use
     * @param  array  $options algo-specific options
     * @return bool|string|null
     */
    public static function passwordHash(string $string, string $algo, array $options = [])
    {
        // Plain text not supported, just an alias. Switch to recommended algo
        if ($algo === 'plaintext') {
            $algo = Auth::DEFAULT_ALGO;
            $options = Auth::DEFAULT_ALGO_OPTIONS;
        }

        if (! \in_array($algo, Auth::SUPPORTED_ALGOS)) {
            throw new \Exception('Hashing algorithm \''.$algo.'\' is not supported.');
        }

        switch ($algo) {
            case 'argon2':
                $hasher = new Argon2($options);

                return $hasher->hash($string);
            case 'bcrypt':
                $hasher = new Bcrypt($options);

                return $hasher->hash($string);
            case 'md5':
                $hasher = new Md5($options);

                return $hasher->hash($string);
            case 'sha':
                $hasher = new Sha($options);

                return $hasher->hash($string);
            case 'phpass':
                $hasher = new Phpass($options);

                return $hasher->hash($string);
            case 'scrypt':
                $hasher = new Scrypt($options);

                return $hasher->hash($string);
            case 'scryptMod':
                $hasher = new Scryptmodified($options);

                return $hasher->hash($string);
            default:
                throw new \Exception('Hashing algorithm \''.$algo.'\' is not supported.');
        }
    }

    /**
     * Password verify.
     *
     * @param  string  $plain
     * @param  string  $hash
     * @param  string  $algo hashing algorithm used to hash
     * @param  array  $options algo-specific options
     * @return bool
     */
    public static function passwordVerify(string $plain, string $hash, string $algo, array $options = [])
    {
        // Plain text not supported, just an alias. Switch to recommended algo
        if ($algo === 'plaintext') {
            $algo = Auth::DEFAULT_ALGO;
            $options = Auth::DEFAULT_ALGO_OPTIONS;
        }

        if (! \in_array($algo, Auth::SUPPORTED_ALGOS)) {
            throw new \Exception('Hashing algorithm \''.$algo.'\' is not supported.');
        }

        switch ($algo) {
            case 'argon2':
                $hasher = new Argon2($options);

                return $hasher->verify($plain, $hash);
            case 'bcrypt':
                $hasher = new Bcrypt($options);

                return $hasher->verify($plain, $hash);
            case 'md5':
                $hasher = new Md5($options);

                return $hasher->verify($plain, $hash);
            case 'sha':
                $hasher = new Sha($options);

                return $hasher->verify($plain, $hash);
            case 'phpass':
                $hasher = new Phpass($options);

                return $hasher->verify($plain, $hash);
            case 'scrypt':
                $hasher = new Scrypt($options);

                return $hasher->verify($plain, $hash);
            case 'scryptMod':
                $hasher = new Scryptmodified($options);

                return $hasher->verify($plain, $hash);
            default:
                throw new \Exception('Hashing algorithm \''.$algo.'\' is not supported.');
        }
    }

    /**
     * Password Generator.
     *
     * Generate random password string
     *
     * @param  int  $length
     * @return string
     */
    public static function passwordGenerator(int $length = 20): string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * Token Generator.
     *
     * Generate random password string
     *
     * @param  int  $length
     * @return string
     */
    public static function tokenGenerator(int $length = 128): string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * Code Generator.
     *
     * Generate random code string
     *
     * @param  int  $length
     * @return string
     */
    public static function codeGenerator(int $length = 6): string
    {
        $value = '';

        for ($i = 0; $i < $length; $i++) {
            $value .= random_int(0, 9);
        }

        return $value;
    }

    /**
     * Verify token and check that its not expired.
     *
     * @param  array  $tokens
     * @param  int  $type
     * @param  string  $secret
     * @return bool|string
     */
    public static function tokenVerify(array $tokens, int $type, string $secret)
    {
        foreach ($tokens as $token) {
            /** @var Document $token */
            if (
                $token->isSet('type') &&
                $token->isSet('secret') &&
                $token->isSet('expire') &&
                $token->getAttribute('type') == $type &&
                $token->getAttribute('secret') === self::hash($secret) &&
                DateTime::formatTz($token->getAttribute('expire')) >= DateTime::formatTz(DateTime::now())
            ) {
                return (string) $token->getId();
            }
        }

        return false;
    }

    public static function phoneTokenVerify(array $tokens, string $secret)
    {
        foreach ($tokens as $token) {
            /** @var Document $token */
            if (
                $token->isSet('type') &&
                $token->isSet('secret') &&
                $token->isSet('expire') &&
                $token->getAttribute('type') == Auth::TOKEN_TYPE_PHONE &&
                $token->getAttribute('secret') === self::hash($secret) &&
                DateTime::formatTz($token->getAttribute('expire')) >= DateTime::formatTz(DateTime::now())
            ) {
                return (string) $token->getId();
            }
        }

        return false;
    }

    /**
     * Verify session and check that its not expired.
     *
     * @param  array  $sessions
     * @param  string  $secret
     * @param  string  $expires
     * @return bool|string
     */
    public static function sessionVerify(array $sessions, string $secret, int $expires)
    {
        foreach ($sessions as $session) {
            /** @var Document $session */
            if (
                $session->isSet('secret') &&
                $session->isSet('provider') &&
                $session->getAttribute('secret') === self::hash($secret) &&
                DateTime::formatTz(DateTime::addSeconds(new \DateTime($session->getCreatedAt()), $expires)) >= DateTime::formatTz(DateTime::now())
            ) {
                return $session->getId();
            }
        }

        return false;
    }

    /**
     * Is Privileged User?
     *
     * @param  array  $roles
     * @return bool
     */
    public static function isPrivilegedUser(array $roles): bool
    {
        if (
            in_array(self::USER_ROLE_OWNER, $roles) ||
            in_array(self::USER_ROLE_DEVELOPER, $roles) ||
            in_array(self::USER_ROLE_ADMIN, $roles)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Is App User?
     *
     * @param  array  $roles
     * @return bool
     */
    public static function isAppUser(array $roles): bool
    {
        if (in_array(self::USER_ROLE_APPS, $roles)) {
            return true;
        }

        return false;
    }

    /**
     * Returns all roles for a user.
     *
     * @param  Document  $user
     * @return array
     */
    public static function getRoles(Document $user): array
    {
        $roles = [];

        if (! self::isPrivilegedUser(Authorization::getRoles()) && ! self::isAppUser(Authorization::getRoles())) {
            if ($user->getId()) {
                $roles[] = Role::user($user->getId())->toString();
                $roles[] = Role::users()->toString();

                $emailVerified = $user->getAttribute('emailVerification', false);
                $phoneVerified = $user->getAttribute('phoneVerification', false);

                if ($emailVerified || $phoneVerified) {
                    $roles[] = Role::user($user->getId(), Roles::DIMENSION_VERIFIED)->toString();
                    $roles[] = Role::users(Roles::DIMENSION_VERIFIED)->toString();
                } else {
                    $roles[] = Role::user($user->getId(), Roles::DIMENSION_UNVERIFIED)->toString();
                    $roles[] = Role::users(Roles::DIMENSION_UNVERIFIED)->toString();
                }
            } else {
                return [Role::guests()->toString()];
            }
        }

        foreach ($user->getAttribute('memberships', []) as $node) {
            if (! isset($node['confirm']) || ! $node['confirm']) {
                continue;
            }

            if (isset($node['$id']) && isset($node['teamId'])) {
                $roles[] = Role::team($node['teamId'])->toString();
                $roles[] = Role::member($node['$id'])->toString();

                if (isset($node['roles'])) {
                    foreach ($node['roles'] as $nodeRole) { // Set all team roles
                        $roles[] = Role::team($node['teamId'], $nodeRole)->toString();
                    }
                }
            }
        }

        return $roles;
    }

    public static function isAnonymousUser(Document $user): bool
    {
        return (is_null($user->getAttribute('email'))
            || is_null($user->getAttribute('phone'))
        ) && is_null($user->getAttribute('password'));
    }
}
