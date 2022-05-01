<?php

namespace Appwrite\Auth;

use Appwrite\Auth\Hash\BCrypt;
use Appwrite\Auth\Hash\MD5;
use Appwrite\Auth\Hash\PHPass;
use Appwrite\Auth\Hash\SCrypt;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

class Auth
{
    /**
     * User Roles.
     */
    const USER_ROLE_ALL = 'all';
    const USER_ROLE_GUEST = 'guest';
    const USER_ROLE_MEMBER = 'member';
    const USER_ROLE_ADMIN = 'admin';
    const USER_ROLE_DEVELOPER = 'developer';
    const USER_ROLE_OWNER = 'owner';
    const USER_ROLE_APP = 'app';
    const USER_ROLE_SYSTEM = 'system';

    /**
     * Token Types.
     */
    const TOKEN_TYPE_LOGIN = 1; // Deprecated
    const TOKEN_TYPE_VERIFICATION = 2;
    const TOKEN_TYPE_RECOVERY = 3;
    const TOKEN_TYPE_INVITE = 4;
    const TOKEN_TYPE_MAGIC_URL = 5;

    /**
     * Session Providers.
     */
    const SESSION_PROVIDER_EMAIL = 'email';
    const SESSION_PROVIDER_ANONYMOUS = 'anonymous';
    const SESSION_PROVIDER_MAGIC_URL = 'magic-url';

    /**
     * Token Expiration times.
     */
    const TOKEN_EXPIRATION_LOGIN_LONG = 31536000;      /* 1 year */
    const TOKEN_EXPIRATION_LOGIN_SHORT = 3600;         /* 1 hour */
    const TOKEN_EXPIRATION_RECOVERY = 3600;            /* 1 hour */
    const TOKEN_EXPIRATION_CONFIRM = 3600 * 24 * 7;    /* 7 days */

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
     * @param string $options algo-specific options
     *
     * @return bool|string|null
     */
    public static function passwordHash(string $string, string $algo, mixed $options = [])
    {
        // TODO: Abstract, somehow.
        switch ($algo) {
            case 'bcrypt':
                $hasher = new BCrypt($options);
                $hash = $hasher->hash($string);
                return $hash;
            case 'scrypt':
                $hasher = new SCrypt($options);
                $hash = $hasher->hash($string);
                return $hash;
            case 'md5':
                $hasher = new MD5($options);
                $hash = $hasher->hash($string);
                return $hash;
            case 'phpass':
                $hahser = new PHPass(8, FALSE);
                $hash = $hahser->hash($string);
                return $hash;
        }

        return null;
    }

    /**
     * Password verify.
     *
     * @param string $plain
     * @param string $hash
     * @param string $algo hashing algorithm used to hash
     * @param string $options algo-specific options
     *
     * @return bool
     */
    public static function passwordVerify(string $plain, string $hash, string $algo, mixed $options = [])
    {

        // TODO: Abstract, somehow.
        switch ($algo) {
            case 'bcrypt':
                $hasher = new BCrypt($options);
                $verify = $hasher->verify($plain, $hash);
                return $verify;
            case 'scrypt':
                $hasher = new SCrypt($options ?? [ 'cost_cpu' => 8, 'cost_memory' => 14, 'cost_parallel' => 1, 'length' => 64 ]);
                $verify = $hasher->verify($plain, $hash);
                return $verify;
            case 'md5':
                $hasher = new MD5($options ?? []);
                $verify = $hasher->verify($plain, $hash);
                return $verify;
            case 'phpass':
                // TODO: Support options
                $hahser = new PHPass(8, FALSE);
                $verify = $hahser->verify($plain, $hash);
                return $verify;
        }

        return false;
    }

    /**
     * Password Generator.
     *
     * Generate random password string
     *
     * @param int $length
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function passwordGenerator(int $length = 20):string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * Token Generator.
     *
     * Generate random password string
     *
     * @param int $length
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function tokenGenerator(int $length = 128):string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * Verify token and check that its not expired.
     *
     * @param array  $tokens
     * @param int    $type
     * @param string $secret
     *
     * @return bool|string
     */
    public static function tokenVerify(array $tokens, int $type, string $secret)
    {
        foreach ($tokens as $token) { /** @var Document $token */
            if ($token->isSet('type') &&
                $token->isSet('secret') &&
                $token->isSet('expire') &&
                $token->getAttribute('type') == $type &&
                $token->getAttribute('secret') === self::hash($secret) &&
                $token->getAttribute('expire') >= \time()) {
                return (string)$token->getId();
            }
        }

        return false;
    }

    /**
     * Verify session and check that its not expired.
     *
     * @param array  $sessions
     * @param string $secret
     *
     * @return bool|string
     */
    public static function sessionVerify(array $sessions, string $secret)
    {
        foreach ($sessions as $session) { /** @var Document $session */
            if ($session->isSet('secret') &&
                $session->isSet('expire') &&
                $session->isSet('provider') &&
                $session->getAttribute('secret') === self::hash($secret) &&
                $session->getAttribute('expire') >= \time()) {
                return (string)$session->getId();
            }
        }

        return false;
    }

    /**
     * Is Privileged User?
     *
     * @param array $roles
     *
     * @return bool
     */
    public static function isPrivilegedUser(array $roles): bool
    {
        if (
            in_array('role:'.self::USER_ROLE_OWNER, $roles) ||
            in_array('role:'.self::USER_ROLE_DEVELOPER, $roles) ||
            in_array('role:'.self::USER_ROLE_ADMIN, $roles)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Is App User?
     *
     * @param array $roles
     *
     * @return bool
     */
    public static function isAppUser(array $roles): bool
    {
        if (in_array('role:'.self::USER_ROLE_APP, $roles)) {
            return true;
        }

        return false;
    }

    /**
     * Returns all roles for a user.
     *
     * @param Document $user
     * @return array
     */
    public static function getRoles(Document $user): array
    {
        $roles = [];

        if (!self::isPrivilegedUser(Authorization::getRoles()) && !self::isAppUser(Authorization::getRoles())) {
            if ($user->getId()) {
                $roles[] = 'user:'.$user->getId();
                $roles[] = 'role:'.Auth::USER_ROLE_MEMBER;
            } else {
                return ['role:'.Auth::USER_ROLE_GUEST];
            }
        }

        foreach ($user->getAttribute('memberships', []) as $node) {
            if (isset($node['teamId']) && isset($node['roles'])) {
                $roles[] = 'team:' . $node['teamId'];

                foreach ($node['roles'] as $nodeRole) { // Set all team roles
                    $roles[] = 'team:' . $node['teamId'] . '/' . $nodeRole;
                }
            }
        }

        return $roles;
    }
}
