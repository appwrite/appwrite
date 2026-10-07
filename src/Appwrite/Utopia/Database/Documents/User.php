<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proof;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Roles;

class User extends Document
{
    public const ROLE_GUESTS = 'guests';
    public const ROLE_USERS = 'users';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_OWNER = 'owner';
    public const ROLE_KEYS = 'keys';
    public const ROLE_SYSTEM = 'system';

    /**
     * Secret checked by the last sessionVerify() call on this instance.
     */
    private ?string $verifiedSecret = null;

    /**
     * Sessions array identity from the last sessionVerify() call.
     */
    private mixed $verifiedSessions = null;

    /**
     * Result of the last sessionVerify() call. Null until the first call.
     */
    private string|false|null $verifiedSessionId = null;

    /**
     * Returns all roles for a user.
     *
     * @return array<string>
     */
    public function getRoles($authorization): array
    {
        $roles = [];

        if (!$this->isKey($authorization->getRoles())) {
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
     * Is Privileged User?
     *
     * @param array<string> $roles
     *
     * @return bool
     */
    public function isPrivileged(array $roles): bool
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
     * Is Key User?
     *
     * @param array<string> $roles
     *
     * @return bool
     */
    public function isKey(array $roles): bool
    {
        if (in_array(self::ROLE_KEYS, $roles)) {
            return true;
        }

        return false;
    }

    public function tokenVerify(?int $type, string $secret, Proof $proofForToken): false|Document
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
     * The session secret is hashed once. Repeating the check for the same
     * secret and sessions array (the user resource and the session resource
     * both do this on every request) reuses that result.
     *
     * @param string $secret
     *
     * @return string|false
     */
    public function sessionVerify(string $secret, Token $proofForToken): string|false
    {
        $sessions = $this->getAttribute('sessions', []);
        if ($this->verifiedSecret === $secret && $this->verifiedSessions === $sessions && $this->verifiedSessionId !== null) {
            return $this->verifiedSessionId;
        }

        $sessionId = false;
        // Session secrets are SHA-256. Hash the cookie secret once and compare.
        // Other proofs (tests, legacy) keep per-session verify(), which may be salted.
        $prepared = $proofForToken->getHash() instanceof Sha;
        $hashed = $prepared ? $proofForToken->hash($secret) : '';

        foreach ($sessions as $session) {
            $sessionSecret = $session->getAttribute('secret');
            $matches = \is_string($sessionSecret) && (
                $prepared
                    ? \hash_equals($sessionSecret, $hashed)
                    : $proofForToken->verify($secret, $sessionSecret)
            );

            if (
                $matches &&
                $session->isSet('provider') &&
                $session->isSet('expire') &&
                DateTime::formatTz(DateTime::format(new \DateTime($session->getAttribute('expire')))) >= DateTime::formatTz(DateTime::now())
            ) {
                $sessionId = $session->getId();
                break;
            }
        }

        $this->verifiedSecret = $secret;
        $this->verifiedSessions = $sessions;
        $this->verifiedSessionId = $sessionId;

        return $sessionId;
    }

    /**
     * Check that a session exists on the user and has not expired.
     *
     * Used by JWT authentication, which binds to a session ID rather than a
     * session secret.
     */
    public function sessionActive(string $sessionId): bool
    {
        $session = $this->find('$id', $sessionId, 'sessions');

        if (empty($session)) {
            return false;
        }

        return $session->isSet('expire')
            && DateTime::formatTz(DateTime::format(new \DateTime($session->getAttribute('expire')))) >= DateTime::formatTz(DateTime::now());
    }

    /**
     * Unix timestamp at which a session of the user expires, or null when the user
     * has no such session or it has no expiry.
     *
     * Used by realtime, which holds a connection open past the request that
     * authenticated it and so has to end it at this time.
     */
    public function getSessionExpiry(string $sessionId): ?int
    {
        $session = $this->find('$id', $sessionId, 'sessions');

        if (empty($session) || !$session->isSet('expire')) {
            return null;
        }

        return (new \DateTime($session->getAttribute('expire')))->getTimestamp();
    }

    public static function invalidateAuthentication(Database $dbForProject, Document $user, ?string $keepSessionId = null): void
    {
        foreach ($user->getAttribute('sessions', []) as $session) {
            if (!$session instanceof Document) {
                continue;
            }
            if ($keepSessionId !== null && $session->getId() === $keepSessionId) {
                continue;
            }
            $dbForProject->deleteDocument('sessions', $session->getId());
        }

        $sequence = $user->getSequence();
        if ($sequence === '' || $sequence === null) {
            return;
        }

        $dbForProject->deleteDocuments('challenges', [
            Query::equal('userInternalId', [$sequence]),
        ]);
    }
}
