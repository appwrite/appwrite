<?php

namespace Utopia\Database\Helpers;

class Role
{
    public function __construct(
        private string $role,
        private string $identifier = '',
        private string $dimension = '',
    ) {
    }

    /**
     * Create a role string from this Role instance
     *
     * @return string
     */
    public function toString(): string
    {
        $str = $this->role;
        if ($this->identifier) {
            $str .= ':' . $this->identifier;
        }
        if ($this->dimension) {
            $str .= '/' . $this->dimension;
        }
        return $str;
    }

    /**
     * @return string
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getDimension(): string
    {
        return $this->dimension;
    }

    /**
     * Parse a role string into a Role object
     *
     * @param string $role
     * @return self
     * @throws \Exception
     */
    public static function parse(string $role): self
    {
        $roleParts = \explode(':', $role);
        $hasIdentifier = \count($roleParts) > 1;
        $hasDimension = \str_contains($role, '/');
        $role = $roleParts[0];

        if (!$hasIdentifier && !$hasDimension) {
            return new self($role);
        }

        if ($hasIdentifier && !$hasDimension) {
            $identifier = $roleParts[1];
            return new self($role, $identifier);
        }

        if (!$hasIdentifier) {
            $dimensionParts = \explode('/', $role);
            if (\count($dimensionParts) !== 2) {
                throw new \Exception('Only one dimension can be provided');
            }

            $role = $dimensionParts[0];
            $dimension = $dimensionParts[1];

            if (empty($dimension)) {
                throw new \Exception('Dimension must not be empty');
            }
            return new self($role, '', $dimension);
        }

        // Has both identifier and dimension
        $dimensionParts = \explode('/', $roleParts[1]);
        if (\count($dimensionParts) !== 2) {
            throw new \Exception('Only one dimension can be provided');
        }

        $identifier = $dimensionParts[0];
        $dimension = $dimensionParts[1];

        if (empty($dimension)) {
            throw new \Exception('Dimension must not be empty');
        }
        return new self($role, $identifier, $dimension);
    }

    /**
     * Create a user role from the given ID
     *
     * @param string $identifier
     * @param string $status
     * @return self
     */
    public static function user(string $identifier, string $status = ''): Role
    {
        return new self('user', $identifier, $status);
    }

    /**
     * Create a users role
     *
     * @param string $status
     * @return self
     */
    public static function users(string $status = ''): self
    {
        return new self('users', '', $status);
    }

    /**
     * Create a team role from the given ID and dimension
     *
     * @param string $identifier
     * @param string $dimension
     * @return self
     */
    public static function team(string $identifier, string $dimension = ''): self
    {
        return new self('team', $identifier, $dimension);
    }

    /**
     * Create a label role from the given ID
     *
     * @param string $identifier
     * @return self
     */
    public static function label(string $identifier): self
    {
        return new self('label', $identifier, '');
    }

    /**
     * Create an any satisfy role
     *
     * @return self
     */
    public static function any(): Role
    {
        return new Role('any');
    }

    /**
     * Create a guests role
     *
     * @return self
     */
    public static function guests(): self
    {
        return new self('guests');
    }

    public static function member(string $identifier): self
    {
        return new self('member', $identifier);
    }
}
