<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Validator\Authorization\Input;
use Utopia\Validator;

class Authorization extends Validator
{
    /**
     * @var bool
     */
    protected bool $status = true;

    /**
     * Default value in case we need
     *  to reset Authorization status
     *
     * @var bool
     */
    protected bool $statusDefault = true;

    /**
     * @var array<string, bool>
     */
    private array $roles = [
        'any' => true
    ];

    /**
     * @var string
     */
    protected string $message = 'Authorization Error';

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /*
     * Validation
     *
     * Returns true if valid or false if not.
    */
    public function isValid(mixed $input): bool
    {
        if (!($input instanceof Input)) {
            $this->message = 'Invalid input provided';
            return false;
        }

        $permissions = $input->getPermissions();
        $action = $input->getAction();

        if (!$this->status) {
            return true;
        }

        if (empty($permissions)) {
            $this->message = 'No permissions provided for action \''.$action.'\'';
            return false;
        }

        $permission = '-';

        foreach ($permissions as $permission) {
            if (\array_key_exists($permission, $this->roles)) {
                return true;
            }
        }

        $this->message = 'Missing "'.$action.'" permission for role "'.$permission.'". Only "'.\json_encode($this->getRoles()).'" scopes are allowed and "'.\json_encode($permissions).'" was given.';
        return false;
    }

    /**
     * @param string $role
     * @return void
     */
    public function addRole(string $role): void
    {
        $this->roles[$role] = true;
    }

    /**
     * @param string $role
     *
     * @return void
     */
    public function removeRole(string $role): void
    {
        unset($this->roles[$role]);
    }

    /**
     * @return array<string>
     */
    public function getRoles(): array
    {
        return \array_keys($this->roles);
    }

    /**
     * @return void
     */
    public function cleanRoles(): void
    {
        $this->roles = [];
    }

    /**
     * @param string $role
     *
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        return (\array_key_exists($role, $this->roles));
    }

    /**
     * Change default status.
     * This will be used for the
     *  value set on the $this->reset() method
     * @param bool $status
     * @return void
     */
    public function setDefaultStatus(bool $status): void
    {
        $this->statusDefault = $status;
        $this->status = $status;
    }

    /**
     * Change status
     *
     * @param bool $status
     * @return void
     */
    public function setStatus(bool $status): void
    {
        $this->status = $status;
    }

    /**
     * Get status
     *
     * @return bool
     */
    public function getStatus(): bool
    {
        return $this->status;
    }

    /**
     * Skip Authorization
     *
     * Skips authorization for the code to be executed inside the callback
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function skip(callable $callback): mixed
    {
        $initialStatus = $this->status;
        $this->disable();

        try {
            return $callback();
        } finally {
            $this->status = $initialStatus;
        }
    }

    /**
     * Enable Authorization checks
     *
     * @return void
     */
    public function enable(): void
    {
        $this->status = true;
    }

    /**
     * Disable Authorization checks
     *
     * @return void
     */
    public function disable(): void
    {
        $this->status = false;
    }

    /**
     * Disable Authorization checks
     *
     * @return void
     */
    public function reset(): void
    {
        $this->status = $this->statusDefault;
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
        return self::TYPE_ARRAY;
    }
}
