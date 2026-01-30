<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Auth\Proof;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Roles;

class User extends Document
{
    public const ROLE_ANY = 'any';
    public const ROLE_GUESTS = 'guests';
    public const ROLE_USERS = 'users';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_OWNER = 'owner';
    public const ROLE_APPS = 'apps';
    public const ROLE_SYSTEM = 'system';

    public function getEmail(): ?string
    {
        return $this->getAttribute('email');
    }

    public function getPhone(): ?string
    {
        return $this->getAttribute('phone');
    }

    /**
     * Returns all roles for a user.
     *
     * @return array<string>
     */
    public function getRoles($authorization): array
    {
        $roles = [];

        if (!$this->isPrivileged($authorization->getRoles()) && !$this->isApp($authorization->getRoles())) {
            if ($this->getId()) {
                $roles[] = Role::user($this->getId())->toString();
                $roles[] = Role::users()->toString();

                $emailVerified = $this->getAttribute('emailVerification', false);
                $phoneVerified = $this->getAttribute('phoneVerification', false);

                if ($emailVerified || $phoneVerified) {
                    $roles[] = Role::user($this->getId(), Roles::DIMENSION_VERIFIED)->toString();
                    $roles[] = Role::users(Roles::DIMENSION_VERIFIED)->toString();
                } else {
                    $roles[] = Role::user($this->getId(), Roles::DIMENSION_UNVERIFIED)->toString();
                    $roles[] = Role::users(Roles::DIMENSION_UNVERIFIED)->toString();
                }
            } else {
                return [Role::guests()->toString()];
            }
        }

        foreach ($this->getAttribute('memberships', []) as $node) {
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

        foreach ($this->getAttribute('labels', []) as $label) {
            $roles[] = 'label:' . $label;
        }

        return $roles;
    }

    /**
     * Check if user is anonymous.
     *
     * @param Document $this
     * @return bool
     */
    public function isAnonymous(): bool
    {
        return is_null($this->getEmail())
            && is_null($this->getPhone());
    }

    /**
     * Is Privileged User?
     *
     * @param array<string> $roles
     *
     * @return bool
     */
    public static function isPrivileged(array $roles): bool
    {
        if (
            in_array(self::ROLE_OWNER, $roles) ||
            in_array(self::ROLE_DEVELOPER, $roles) ||
            in_array(self::ROLE_ADMIN, $roles)
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
    public static function isApp(array $roles): bool
    {
        if (in_array(self::ROLE_APPS, $roles)) {
            return true;
        }

        return false;
    }

    public function tokenVerify(int $type = null, string $secret, Proof $proofForToken): false|Document
    {
        $tokens = $this->getAttribute('tokens', []);
        foreach ($tokens as $token) {
            if (
                $token->isSet('secret') &&
                $token->isSet('expire') &&
                $token->isSet('type') &&
                ($type === null ||  $token->getAttribute('type') === $type) &&
                $proofForToken->verify($secret, $token->getAttribute('secret')) &&
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
    public function sessionVerify(string $secret, Token $proofForToken)
    {
        $sessions = $this->getAttribute('sessions', []);

        foreach ($sessions as $session) {
            if (
                $session->isSet('secret') &&
                $session->isSet('provider') &&
                $session->isSet('expire') &&
                $proofForToken->verify($secret, $session->getAttribute('secret')) &&
                DateTime::formatTz(DateTime::format(new \DateTime($session->getAttribute('expire')))) >= DateTime::formatTz(DateTime::now())
            ) {
                return $session->getId();
            }
        }

        return false;

        return false;
    }
}
