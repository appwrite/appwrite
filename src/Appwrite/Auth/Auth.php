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
        'plaintext'
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
     * Activity associated with user or the app.
     */
    public const ACTIVITY_TYPE_APP = 'app';
    public const ACTIVITY_TYPE_USER = 'user';
    public const ACTIVITY_TYPE_GUEST = 'guest';

    /**
     * Token Types.
     */
    public const TOKEN_TYPE_LOGIN = 1; // Deprecated
    public const TOKEN_TYPE_VERIFICATION = 2;
    public const TOKEN_TYPE_RECOVERY = 3;
    public const TOKEN_TYPE_INVITE = 4;
    public const TOKEN_TYPE_MAGIC_URL = 5;
    public const TOKEN_TYPE_PHONE = 6;
    public const TOKEN_TYPE_OAUTH2 = 7;
    public const TOKEN_TYPE_GENERIC = 8;
    public const TOKEN_TYPE_EMAIL = 9; // OTP

    /**
     * Session Providers.
     */
    public const SESSION_PROVIDER_EMAIL = 'email';
    public const SESSION_PROVIDER_ANONYMOUS = 'anonymous';
    public const SESSION_PROVIDER_MAGIC_URL = 'magic-url';
    public const SESSION_PROVIDER_PHONE = 'phone';
    public const SESSION_PROVIDER_OAUTH2 = 'oauth2';
    public const SESSION_PROVIDER_TOKEN = 'token';
    public const SESSION_PROVIDER_SERVER = 'server';

    /**
     * Token Expiration times.
     */
    public const TOKEN_EXPIRATION_LOGIN_LONG = 31536000;      /* 1 year */
    public const TOKEN_EXPIRATION_LOGIN_SHORT = 3600;         /* 1 hour */
    public const TOKEN_EXPIRATION_RECOVERY = 3600;            /* 1 hour */
    public const TOKEN_EXPIRATION_CONFIRM = 3600 * 1;         /* 1 hour */
    public const TOKEN_EXPIRATION_OTP = 60 * 15;            /* 15 minutes */
    public const TOKEN_EXPIRATION_GENERIC = 60 * 15;        /* 15 minutes */

    /**
     * Token Lengths.
     */
    public const TOKEN_LENGTH_MAGIC_URL = 64;
    public const TOKEN_LENGTH_VERIFICATION = 256;
    public const TOKEN_LENGTH_RECOVERY = 256;
    public const TOKEN_LENGTH_OAUTH2 = 64;
    public const TOKEN_LENGTH_SESSION = 256;

    /**
     * MFA
     */
    public const MFA_RECENT_DURATION = 1800; // 30 mins

    /**
     * @var string
     */
    public static $cookieName = 'a_session';

    /**
     * @var string
     */
    public static $cookieNamePreview = 'a_jwt_console';

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
     *
     * @return string
     */
    public static function setCookieName($string)
    {
        return self::$cookieName = $string;
    }

    /**
     * Encode Session.
     *
     * @param string $id
     * @param string $secret
     *
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
     * Token type to session provider mapping.
     */
    public static function getSessionProviderByTokenType(int $type): string
    {
        switch ($type) {
            case Auth::TOKEN_TYPE_VERIFICATION:
            case Auth::TOKEN_TYPE_RECOVERY:
            case Auth::TOKEN_TYPE_INVITE:
                return Auth::SESSION_PROVIDER_EMAIL;
            case Auth::TOKEN_TYPE_MAGIC_URL:
                return Auth::SESSION_PROVIDER_MAGIC_URL;
            case Auth::TOKEN_TYPE_PHONE:
                return Auth::SESSION_PROVIDER_PHONE;
            case Auth::TOKEN_TYPE_OAUTH2:
                return Auth::SESSION_PROVIDER_OAUTH2;
            default:
                return Auth::SESSION_PROVIDER_TOKEN;
        }
    }

    /**
     * Decode Session.
     *
     * @param string $session
     *
     * @return array
     *
     * @throws \Exception
     */
    public static function decodeSession($session)
    {
        $session = \json_decode(\base64_decode($session), true);
        $default = ['id' => null, 'secret' => ''];

        if (!\is_array($session)) {
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
     *
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
     * @param string $string
     * @param string $algo hashing algorithm to use
     * @param array $options algo-specific options
     *
     * @return bool|string|null
     */
    public static function passwordHash(string $string, string $algo, array $options = [])
    {
        // Plain text not supported, just an alias. Switch to recommended algo
        if ($algo === 'plaintext') {
            $algo = Auth::DEFAULT_ALGO;
            $options = Auth::DEFAULT_ALGO_OPTIONS;
        }

        if (!\in_array($algo, Auth::SUPPORTED_ALGOS)) {
            throw new \Exception('Hashing algorithm \'' . $algo . '\' is not supported.');
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
                throw new \Exception('Hashing algorithm \'' . $algo . '\' is not supported.');
        }
    }

    /**
     * Password verify.
     *
     * @param string $plain
     * @param string $hash
     * @param string $algo hashing algorithm used to hash
     * @param array $options algo-specific options
     *
     * @return bool
     */
    public static function passwordVerify(string $plain, string $hash, string $algo, array $options = [])
    {
        // Plain text not supported, just an alias. Switch to recommended algo
        if ($algo === 'plaintext') {
            $algo = Auth::DEFAULT_ALGO;
            $options = Auth::DEFAULT_ALGO_OPTIONS;
        }

        if (!\in_array($algo, Auth::SUPPORTED_ALGOS)) {
            throw new \Exception('Hashing algorithm \'' . $algo . '\' is not supported.');
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
                throw new \Exception('Hashing algorithm \'' . $algo . '\' is not supported.');
        }
    }

    /**
     * Password Generator.
     *
     * Generate random password string
     *
     * @param int $length
     *
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
     * @param int $length Length of returned token
     *
     * @return string
     */
    public static function tokenGenerator(int $length = 256): string
    {
        if ($length <= 0) {
            throw new \Exception('Token length must be greater than 0');
        }

        $bytesLength = (int) ceil($length / 2);
        $token = \bin2hex(\random_bytes($bytesLength));

        return substr($token, 0, $length);
    }

    /**
     * Code Generator.
     *
     * Generate random code string
     *
     * @param int $length
     *
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
     * @param array<Document> $tokens
     * @param int $type Type of token to verify, if null will verify any type
     * @param string $secret
     *
     * @return false|Document
     */
    public static function tokenVerify(array $tokens, int $type = null, string $secret): false|Document
    {
        foreach ($tokens as $token) {
            if (
                $token->isSet('secret') &&
                $token->isSet('expire') &&
                $token->isSet('type') &&
                ($type === null ||  $token->getAttribute('type') === $type) &&
                $token->getAttribute('secret') === self::hash($secret) &&
                DateTime::formatTz($token->getAttribute('expire')) >= DateTime::formatTz(DateTime::now())
            ) {
                return $token;
            }
        }

        return false;
    }

    /**
     * Verify session and check that its not expired.
     *
     * @param array<Document> $sessions
     * @param string $secret
     *
     * @return bool|string
     */
    public static function sessionVerify(array $sessions, string $secret)
    {
        foreach ($sessions as $session) {
            if (
                $session->isSet('secret') &&
                $session->isSet('provider') &&
                $session->getAttribute('secret') === self::hash($secret) &&
                DateTime::formatTz(DateTime::format(new \DateTime($session->getAttribute('expire')))) >= DateTime::formatTz(DateTime::now())
            ) {
                return $session->getId();
            }
        }

        return false;
    }

    /**
     * Is Privileged User?
     *
     * @param array<string> $roles
     *
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
     * @param array<string> $roles
     *
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
     * @param Document $user
     * @return array<string>
     */
    public static function getRoles(Document $user): array
    {
        $roles = [];

        if (!self::isPrivilegedUser(Authorization::getRoles()) && !self::isAppUser(Authorization::getRoles())) {
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
            if (!isset($node['confirm']) || !$node['confirm']) {
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

        foreach ($user->getAttribute('labels', []) as $label) {
            $roles[] = 'label:' . $label;
        }

        return $roles;
    }

    /**
     * Check if user is anonymous.
     *
     * @param Document $user
     * @return bool
     */
    public static function isAnonymousUser(Document $user): bool
    {
        return is_null($user->getAttribute('email'))
            && is_null($user->getAttribute('phone'));
    }
}
