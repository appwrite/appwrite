<?php

namespace Appwrite\Auth\Validator;

use Utopia\Database\Helpers\Role as DBRole;
use Utopia\Database\Validator\Roles;
use Utopia\Validator;

class Role extends Validator
{
    /**
     * @var array
     */
    protected array $roles;


    /**
     * Constructor
     *
     * Sets the acceptable roles.
     *
     * @param  array  $list
     * @param  string  $type of $list items
     */
    public function __construct(array $roles)
    {
        $this->roles = $roles;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Value must be one of (' . \implode(', ', $this->roles) . ' or of the format "project:<projectId>/<role>")';
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
     * Is valid
     *
     * Validation will pass if $value is in the white list array.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if (\is_array($value)) {
            return false;
        }

        $role = DBRole::parse($value);
        $valid = $role->getRole() === Roles::ROLE_PROJECT ? !empty($role->getIdentifier()) && \in_array($role->getDimension(), $this->roles) : \in_array($role->getRole(), $this->roles);

        return $valid;
    }
}
