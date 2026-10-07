<?php

namespace Utopia\Database;

use ArrayObject;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Structure as StructureException;

/**
 * @extends ArrayObject<string, mixed>
 */
class Document extends ArrayObject
{
    public const SET_TYPE_ASSIGN = 'assign';
    public const SET_TYPE_PREPEND = 'prepend';
    public const SET_TYPE_APPEND = 'append';

    /**
     * Construct.
     *
     * Construct a new fields object
     *
     * @param array<string, mixed> $input
     * @throws DatabaseException
     * @see ArrayObject::__construct
     *
     */
    public function __construct(array $input = [])
    {
        if (array_key_exists('$id', $input) && !\is_string($input['$id'])) {
            throw new StructureException('$id must be of type string');
        }

        if (array_key_exists('$permissions', $input) && !is_array($input['$permissions'])) {
            throw new StructureException('$permissions must be of type array');
        }

        foreach ($input as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }

            if (isset($value['$id']) || isset($value['$collection'])) {
                $input[$key] = new self($value);
                continue;
            }

            $converted = false;
            foreach ($value as $childKey => $child) {
                // An array value is either a list of nested sub-documents or a list of
                // plain items (dates, numbers, strings): wrap the former, leave the latter.
                // is_array() tells them apart and avoids array-accessing a non-array
                // value (e.g. a UTCDateTime), which would otherwise fatal.
                if (\is_array($child) && (isset($child['$id']) || isset($child['$collection']))) {
                    $value[$childKey] = new self($child);
                    $converted = true;
                }
            }

            if ($converted) {
                $input[$key] = $value;
            }
        }

        parent::__construct($input);
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->getAttribute('$id', '');
    }

    /**
     * @return string|null
     */
    public function getSequence(): ?string
    {
        $sequence = $this->getAttribute('$sequence');

        if ($sequence === null) {
            return null;
        }

        return $sequence;
    }

    /**
     * @return string
     */
    public function getCollection(): string
    {
        return $this->getAttribute('$collection', '');
    }

    /**
     * @return array<string>
     */
    public function getPermissions(): array
    {
        return \array_values(\array_unique($this->getAttribute('$permissions', [])));
    }

    /**
     * @return array<string>
     */
    public function getRead(): array
    {
        return $this->getPermissionsByType(Database::PERMISSION_READ);
    }

    /**
     * @return array<string>
     */
    public function getCreate(): array
    {
        return $this->getPermissionsByType(Database::PERMISSION_CREATE);
    }

    /**
     * @return array<string>
     */
    public function getUpdate(): array
    {
        return $this->getPermissionsByType(Database::PERMISSION_UPDATE);
    }

    /**
     * @return array<string>
     */
    public function getDelete(): array
    {
        return $this->getPermissionsByType(Database::PERMISSION_DELETE);
    }

    /**
     * @return array<string>
     */
    public function getWrite(): array
    {
        return \array_unique(\array_intersect(
            $this->getCreate(),
            $this->getUpdate(),
            $this->getDelete()
        ));
    }

    /**
     * @return array<string>
     */
    public function getPermissionsByType(string $type): array
    {
        $typePermissions = [];

        foreach ($this->getPermissions() as $permission) {
            if (!\str_starts_with($permission, $type)) {
                continue;
            }
            $typePermissions[] = \str_replace([$type . '(', ')', '"', ' '], '', $permission);
        }

        return \array_unique($typePermissions);
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getAttribute('$createdAt');
    }

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getAttribute('$updatedAt');
    }

    /**
     * @return int|string|null
     */
    public function getTenant(): int|string|null
    {
        $tenant = $this->getAttribute('$tenant');

        if (\is_numeric($tenant)) {
            return (int) $tenant;
        }

        return $tenant;
    }

    /**
     * Get Document Attributes
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $attributes = [];

        $internalKeys = \array_map(
            fn ($attr) => $attr['$id'],
            Database::INTERNAL_ATTRIBUTES
        );

        foreach ($this as $attribute => $value) {
            if (\in_array($attribute, $internalKeys)) {
                continue;
            }

            $attributes[$attribute] = $value;
        }

        return $attributes;
    }

    /**
     * Get Attribute.
     *
     * Method for getting a specific fields attribute. If $name is not found $default value will be returned.
     *
     * @param string $name
     * @param mixed $default
     *
     * @return mixed
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if (isset($this[$name])) {
            return $this[$name];
        }

        return $default;
    }

    /**
     * Set Attribute.
     *
     * Method for setting a specific field attribute
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     *
     * @return static
     */
    public function setAttribute(string $key, mixed $value, string $type = self::SET_TYPE_ASSIGN): static
    {
        switch ($type) {
            case self::SET_TYPE_ASSIGN:
                $this[$key] = $value;
                break;
            case self::SET_TYPE_APPEND:
                $this[$key] = (!isset($this[$key]) || !\is_array($this[$key])) ? [] : $this[$key];
                \array_push($this[$key], $value);
                break;
            case self::SET_TYPE_PREPEND:
                $this[$key] = (!isset($this[$key]) || !\is_array($this[$key])) ? [] : $this[$key];
                \array_unshift($this[$key], $value);
                break;
        }

        return $this;
    }

    /**
     * Set Attributes.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function setAttributes(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Remove Attribute.
     *
     * Method for removing a specific field attribute
     *
     * @param string $key
     *
     * @return static
     */
    public function removeAttribute(string $key): static
    {
        unset($this[$key]);

        /* @phpstan-ignore-next-line */
        return $this;
    }

    /**
     * Find.
     *
     * @param string $key
     * @param mixed $find
     * @param string $subject
     *
     * @return mixed
     */
    public function find(string $key, $find, string $subject = ''): mixed
    {
        $subject = $this[$subject] ?? null;
        $subject = (empty($subject)) ? $this : $subject;

        if (is_array($subject)) {
            foreach ($subject as $i => $value) {
                if (isset($value[$key]) && $value[$key] === $find) {
                    return $value;
                }
            }
            return false;
        }

        if (isset($subject[$key]) && $subject[$key] === $find) {
            return $subject;
        }
        return false;
    }

    /**
     * Find and Replace.
     *
     * Get array child by key and value match
     *
     * @param string $key
     * @param mixed $find
     * @param mixed $replace
     * @param string $subject
     *
     * @return bool
     */
    public function findAndReplace(string $key, $find, $replace, string $subject = ''): bool
    {
        $subject = &$this[$subject] ?? null;
        $subject = (empty($subject)) ? $this : $subject;

        if (is_array($subject)) {
            foreach ($subject as $i => &$value) {
                if (isset($value[$key]) && $value[$key] === $find) {
                    $value = $replace;
                    return true;
                }
            }
            return false;
        }

        if (isset($subject[$key]) && $subject[$key] === $find) {
            $subject[$key] = $replace;
            return true;
        }
        return false;
    }

    /**
     * Find and Remove.
     *
     * Get array child by key and value match
     *
     * @param string $key
     * @param mixed $find
     * @param string $subject
     *
     * @return bool
     */
    public function findAndRemove(string $key, $find, string $subject = ''): bool
    {
        $subject = &$this[$subject] ?? null;
        $subject = (empty($subject)) ? $this : $subject;

        if (is_array($subject)) {
            foreach ($subject as $i => &$value) {
                if (isset($value[$key]) && $value[$key] === $find) {
                    unset($subject[$i]);
                    return true;
                }
            }
            return false;
        }

        if (isset($subject[$key]) && $subject[$key] === $find) {
            unset($subject[$key]);
            return true;
        }
        return false;
    }

    /**
     * Checks if document has data.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return !\count($this);
    }

    /**
     * Checks if a document key is set.
     *
     * @param string $key
     *
     * @return bool
     */
    public function isSet(string $key): bool
    {
        return isset($this[$key]);
    }

    /**
     * Get Array Copy.
     *
     * Outputs entity as a PHP array
     *
     * @param array<string> $allow
     * @param array<string> $disallow
     *
     * @return array<string, mixed>
     */
    public function getArrayCopy(array $allow = [], array $disallow = []): array
    {
        $array = parent::getArrayCopy();

        $output = [];

        foreach ($array as $key => $value) {
            if (!empty($allow) && !\in_array($key, $allow)) { // Export only allow fields
                continue;
            }

            if (!empty($disallow) && \in_array($key, $disallow)) { // Don't export disallowed fields
                continue;
            }

            if ($value instanceof self) {
                $output[$key] = $value->getArrayCopy($allow, $disallow);
            } elseif (\is_array($value)) {
                $value = \array_map(
                    fn ($item) => $item instanceof self ? $item->getArrayCopy($allow, $disallow) : $item,
                    $value
                );

                $output[$key] = $value;
            } else {
                $output[$key] = $value;
            }
        }

        return $output;
    }

    public function __clone()
    {
        foreach ($this as $key => $value) {
            if ($value instanceof self) {
                $this[$key] = clone $value;
            } elseif (\is_array($value)) {
                $this[$key] = \array_map(fn ($item) => $item instanceof self ? clone $item : $item, $value);
            }
        }
    }
}
