<?php

namespace Appwrite\Auth\Validator;

use Appwrite\Auth\Pwned;
use Appwrite\Extend\Exception;

/**
 * Validates a password against the project's `password-pwned` policy.
 *
 * The lookup itself belongs to the configured adapter, see `Appwrite\Auth\Pwned`.
 * This class only decides whether the policy asks for one.
 */
class PasswordPwned extends Password
{
    protected bool $enabled;
    protected bool $sessions;
    protected bool $users;
    protected Pwned $adapter;

    /**
     * @param array<string, mixed> $policy the project's `passwordPwned` auth settings
     */
    public function __construct(Pwned $adapter, array $policy = [], bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty);

        $this->adapter = $adapter;
        $this->enabled = (bool) ($policy['enabled'] ?? true);
        $this->sessions = (bool) ($policy['sessions'] ?? false);
        $this->users = (bool) ($policy['users'] ?? false);
    }

    /**
     * Whether the project enforces the policy at all.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether passwords are checked when a session is created.
     */
    public function checksSessions(): bool
    {
        return $this->enabled && $this->sessions;
    }

    /**
     * Whether users signing in with a breached password are blocked until they reset it.
     *
     * Only takes effect when sessions are checked.
     */
    public function blocksUsers(): bool
    {
        return $this->users;
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Password must not have been exposed in a known data breach.';
    }

    /**
     * Whether the password appears in a known breach.
     *
     * Returns null only when the policy is disabled.
     *
     * @throws Exception when the breach service cannot be reached
     */
    public function check(string $password): ?bool
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->adapter->isPwned($password);
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     * @throws Exception when the breach service cannot be reached
     */
    public function isValid($value): bool
    {
        if (!parent::isValid($value)) {
            return false;
        }

        if ($this->allowEmpty && \strlen($value) === 0) {
            return true;
        }

        return $this->check($value) !== true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
