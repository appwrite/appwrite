<?php

namespace Auth;

use Database\Document;

class Auth
{
    /**
     * User Gender
     */
    const USER_GENDER_TYPE_NOT_SET  = 0;
    const USER_GENDER_TYPE_MALE     = 1;
    const USER_GENDER_TYPE_FEMALE   = 2;
    const USER_GENDER_TYPE_OTHER    = 3;

    /**
     * User Status
     */
    const USER_STATUS_UNACTIVATED   = 0;
    const USER_STATUS_ACTIVATED     = 1;
    const USER_STATUS_BLOCKED       = 2;

    /**
     * User Types
     */
    const USER_TYPE_USER            = 0;
    const USER_TYPE_PARENT          = 1;
    const USER_TYPE_APP             = 2;

    /**
     * User Roles
     */
    const USER_ROLE_GUEST           = 0;
    const USER_ROLE_MEMBER          = 1;
    const USER_ROLE_ADMIN           = 2;
    const USER_ROLE_DEVELOPER       = 3;
    const USER_ROLE_OWNER           = 4;
    const USER_ROLE_APP             = 5;
    const USER_ROLE_SYSTEM          = 6;
    const USER_ROLE_ALL             = '*';

    /**
     * Token Types
     */
    const TOKEN_TYPE_LOGIN  	= 1;
    const TOKEN_TYPE_CONFIRM    = 2;
    const TOKEN_TYPE_RECOVERY   = 3;
    const TOKEN_TYPE_INVITE     = 4;

    /**
     * Token Expiration times
     */
    const TOKEN_EXPIRATION_LOGIN_LONG 	    = 31536000;         /* 1 year */
    const TOKEN_EXPIRATION_LOGIN_SHORT 	    = 3600;             /* 1 hour */
    const TOKEN_EXPIRATION_RECOVERY     	= 3600;   	        /* 1 hour */
    const TOKEN_EXPIRATION_CONFIRM     	    = 3600 * 24 * 7;	/* 7 days */

    /**
     * @var string
     */
    static public $cookieName = 'a-session';

    /**
     * User Unique ID
     *
     * @var int
     */
    static public $unique = 0;

    /**
     * User Secret Key
     *
     * @var string
     */
    static public $secret = '';

    /**
     * Set Cookie Name
     *
     * @param $string
     * @return string
     */
    static public function setCookieName($string)
    {
        return self::$cookieName = $string;
    }

    /**
     * Encode Session
     *
     * @param int $id
     * @param string $secret
     * @return string
     */
    static public function encodeSession($id, $secret)
    {
        return base64_encode(json_encode([
            'id' => $id,
            'secret' => $secret,
        ]));
    }

    /**
     * Decode Session
     *
     * @param string $session
     * @return array
     * @throws \Exception
     */
    static public function decodeSession($session)
    {
        $session = json_decode(base64_decode($session), true);
        $default = ['id' => null, 'secret' => '',];

        if(!is_array($session)) {
            return $default;
        }

        return array_merge($default, $session);
    }

    /**
     * Encode
     *
     * One-way encryption
     *
     * @param $string
     * @return string
     */
    static public function hash($string)
    {
        return hash('sha256',$string);
    }

    /**
     * Password Hash
     *
     * One way string hashing for user passwords
     *
     * @param $string
     * @return bool|string
     */
    static public function passwordHash($string)
    {
        return password_hash($string, PASSWORD_BCRYPT, array('cost' => 8));
    }

    /**
     * Password verify
     *
     * @param $plain
     * @param $hash
     * @return bool
     */
    static public function passwordVerify($plain, $hash)
    {
        return password_verify($plain, $hash);
    }

    /**
     * Password Generator
     *
     * Generate random password string
     *
     * @param int $length
     * @return string
     * @throws \Exception
     */
    static public function passwordGenerator(int $length = 20):string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Token Generator
     *
     * Generate random password string
     *
     * @param int $length
     * @return string
     * @throws \Exception
     */
    static public function tokenGenerator(int $length = 128):string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Verify token and check that its not expired
     *
     * @param array $tokens
     * @param int $type
     * @param string $secret
     * @return bool|int
     */
    static public function tokenVerify(array $tokens, int $type, string $secret)
    {
        foreach ($tokens as $token) { /* @var $token Document */
            if(isset($token['type']) &&
               isset($token['secret']) &&
               isset($token['expire']) &&
               $token['type'] == $type &&
               $token['secret'] === self::hash($secret) &&
               $token['expire']  >= time()) {
                return $token->getUid();
            }
        }

        return false;
    }
}