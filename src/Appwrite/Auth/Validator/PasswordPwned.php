<?php

namespace Appwrite\Auth\Validator;

use Appwrite\Extend\Exception;
use Utopia\Fetch\Client;

/**
 * Validates a password against the project's `password-pwned` policy.
 *
 * Subclasses differ in who answers the question and in what leaves the server,
 * so read the one you are configuring. `_APP_PWNED_PASSWORDS_DSN` picks it, and
 * the DSN scheme matches the subclass.
 */
abstract class PasswordPwned extends Password
{
    private const CONNECT_TIMEOUT = 3 * 1000; // milliseconds
    private const REQUEST_TIMEOUT = 5 * 1000; // milliseconds

    protected bool $enabled;
    protected bool $sessions;
    protected bool $users;

    /**
     * @param array<string, mixed> $policy the project's `passwordPwned` auth settings
     */
    public function __construct(array $policy = [], bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty);

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
     * Is valid.
     *
     * A password the policy did not look at is valid, so callers that need to
     * tell "clean" from "never checked" ask `isEnabled()` first.
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

        if (!$this->enabled) {
            return true;
        }

        return !$this->isPwned($value);
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

    /**
     * Whether the password appears in a known data breach.
     *
     * @throws Exception when the breach service cannot be reached or answers with nonsense
     */
    abstract protected function isPwned(string $password): bool;

    /**
     * A client with the timeouts a password check can afford to wait.
     */
    protected function client(?Client $client = null): Client
    {
        return $client ?? (new Client())
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setTimeout(self::REQUEST_TIMEOUT)
            ->setAllowRedirects(false)
            ->setUserAgent('Appwrite');
    }

    /**
     * A password is never silently accepted when the lookup did not happen.
     *
     * @throws Exception
     */
    protected function unavailable(): never
    {
        throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
    }
}
