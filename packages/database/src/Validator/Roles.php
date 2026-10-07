<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Helpers\Role;
use Utopia\Validator;

class Roles extends Validator
{
    // Roles
    public const ROLE_ANY = 'any';
    public const ROLE_GUESTS = 'guests';
    public const ROLE_USERS = 'users';
    public const ROLE_USER = 'user';
    public const ROLE_TEAM = 'team';
    public const ROLE_MEMBER = 'member';
    public const ROLE_LABEL = 'label';

    public const ROLES = [
        self::ROLE_ANY,
        self::ROLE_GUESTS,
        self::ROLE_USERS,
        self::ROLE_USER,
        self::ROLE_TEAM,
        self::ROLE_MEMBER,
        self::ROLE_LABEL,
    ];

    protected string $message = 'Roles Error';

    /**
     * @var array<string>
     */
    protected array $allowed;

    protected int $length;

    public const CONFIG = [
        self::ROLE_ANY => [
            'identifier' => [
                'allowed' => false,
                'required' => false,
            ],
            'dimension' => [
                'allowed' => false,
                'required' => false,
            ],
        ],
        self::ROLE_GUESTS => [
            'identifier' => [
                'allowed' => false,
                'required' => false,
            ],
            'dimension' => [
                'allowed' => false,
                'required' => false,
            ],
        ],
        self::ROLE_USERS => [
            'identifier' => [
                'allowed' => false,
                'required' => false,
            ],
            'dimension' => [
                'allowed' => true,
                'required' => false,
                'options' => self::USER_DIMENSIONS
            ],
        ],
        self::ROLE_USER => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' => [
                'allowed' => true,
                'required' => false,
                'options' => self::USER_DIMENSIONS
            ],
        ],
        self::ROLE_TEAM => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' => [
                'allowed' => true,
                'required' => false,
            ],
        ],
        self::ROLE_MEMBER => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' => [
                'allowed' => false,
                'required' => false,
            ],
        ],
        self::ROLE_LABEL => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' => [
                'allowed' => false,
                'required' => false,
            ],
        ],
    ];

    // Dimensions
    public const DIMENSION_VERIFIED = 'verified';
    public const DIMENSION_UNVERIFIED = 'unverified';

    public const USER_DIMENSIONS = [
        self::DIMENSION_VERIFIED,
        self::DIMENSION_UNVERIFIED,
    ];

    /**
     * Roles constructor.
     *
     * @param int $length maximum amount of role. 0 means unlimited.
     * @param array<string> $allowed allowed roles. Defaults to all available.
     */
    public function __construct(int $length = 0, array $allowed = self::ROLES)
    {
        $this->length = $length;
        $this->allowed = $allowed;
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
        return $this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param mixed $roles
     *
     * @return bool
     */
    public function isValid($roles): bool
    {
        if (!\is_array($roles)) {
            $this->message = 'Roles must be an array of strings.';
            return false;
        }

        if ($this->length && \count($roles) > $this->length) {
            $this->message = 'You can only provide up to ' . $this->length . ' roles.';
            return false;
        }

        foreach ($roles as $role) {
            if (!\is_string($role)) {
                $this->message = 'Every role must be of type string.';
                return false;
            }
            if ($role === '*') {
                $this->message = 'Wildcard role "*" has been replaced. Use "any" instead.';
                return false;
            }
            if (\str_contains($role, 'role:')) {
                $this->message = 'Roles using the "role:" prefix have been removed. Use "users", "guests", or "any" instead.';
                return false;
            }

            $isAllowed = false;
            foreach ($this->allowed as $allowed) {
                if (\str_starts_with($role, $allowed)) {
                    $isAllowed = true;
                    break;
                }
            }
            if (!$isAllowed) {
                $this->message = 'Role "' . $role . '" is not allowed. Must be one of: ' . \implode(', ', $this->allowed) . '.';
                return false;
            }

            try {
                $role = Role::parse($role);
            } catch (\Exception $e) {
                $this->message = $e->getMessage();
                return false;
            }

            $roleName = $role->getRole();
            $identifier = $role->getIdentifier();
            $dimension = $role->getDimension();

            if (!$this->isValidRole($roleName, $identifier, $dimension)) {
                return false;
            }
        }
        return true;
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

    protected function isValidRole(
        string $role,
        string $identifier,
        string $dimension
    ): bool {
        $identifierValidator = match ($role) {
            self::ROLE_LABEL => new Label(),
            default => new Key(),
        };
        /**
         * For project-specific permissions, roles will be in the format `project-<projectId>-<role>`.
         * Template takes 9 characters, `projectId` and `role` can be upto 36 characters. In total, 81 characters.
         */
        $dimensionValidator = new Key(maxLength: 81);

        $config = self::CONFIG[$role] ?? null;

        if (empty($config)) {
            $this->message = 'Role "' . $role . '" is not allowed. Must be one of: ' . \implode(', ', self::ROLES) . '.';
            return false;
        }

        // Process identifier configuration
        $allowed = $config['identifier']['allowed'];
        $required = $config['identifier']['required'];

        // Not allowed and has an identifier
        if (!$allowed && !empty($identifier)) {
            $this->message = 'Role "' . $role . '"' . ' can not have an ID value.';
            return false;
        }

        // Required and has no identifier
        if ($allowed && $required && empty($identifier)) {
            $this->message = 'Role "' . $role . '"' . ' must have an ID value.';
            return false;
        }

        // Allowed and has an invalid identifier
        if ($allowed && !empty($identifier) && !$identifierValidator->isValid($identifier)) {
            $this->message = 'Role "' . $role . '"' . ' identifier value is invalid: ' . $identifierValidator->getDescription();
            return false;
        }

        // Process dimension configuration
        $allowed = $config['dimension']['allowed'];
        $required = $config['dimension']['required'];
        $options = $config['dimension']['options'] ?? [$dimension];

        // Not allowed and has a dimension
        if (!$allowed && !empty($dimension)) {
            $this->message = 'Role "' . $role . '"' . ' can not have a dimension value.';
            return false;
        }

        // Required and has no dimension
        // PHPStan complains because there are currently no dimensions that are required, but there might be in future
        // @phpstan-ignore-next-line
        if ($allowed && $required && empty($dimension)) {
            $this->message = 'Role "' . $role . '"' . ' must have a dimension value.';
            return false;
        }

        if ($allowed && !empty($dimension)) {
            // Allowed and dimension is not an allowed option
            if (!\in_array($dimension, $options)) {
                $this->message = 'Role "' . $role . '"' . ' dimension value is invalid. Must be one of: ' . \implode(', ', $options) . '.';
                return false;
            }
            // Allowed and dimension is not a valid key
            if (!$dimensionValidator->isValid($dimension)) {
                $this->message = 'Role "' . $role . '"' . ' dimension value is invalid: ' . $dimensionValidator->getDescription();
                return false;
            }
        }

        return true;
    }
}
