<?php

namespace Appwrite\Auth;

use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;

class Auth
{
    /**
     * @var string
     */
    public static $cookieNamePreview = 'a_jwt_console';

    /**
     * Token type to session provider mapping.
     */
    public static function getSessionProviderByTokenType(int $type): string
    {
        switch ($type) {
            case TOKEN_TYPE_VERIFICATION:
            case TOKEN_TYPE_RECOVERY:
            case TOKEN_TYPE_INVITE:
                return SESSION_PROVIDER_EMAIL;
            case TOKEN_TYPE_MAGIC_URL:
                return SESSION_PROVIDER_MAGIC_URL;
            case TOKEN_TYPE_PHONE:
                return SESSION_PROVIDER_PHONE;
            case TOKEN_TYPE_OAUTH2:
                return SESSION_PROVIDER_OAUTH2;
            default:
                return SESSION_PROVIDER_TOKEN;
        }
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
            in_array(USER_ROLE_OWNER, $roles) ||
            in_array(USER_ROLE_DEVELOPER, $roles) ||
            in_array(USER_ROLE_ADMIN, $roles)
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
        if (in_array(USER_ROLE_APPS, $roles)) {
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
