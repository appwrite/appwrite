<?php

declare(strict_types=1);

namespace Utopia;

abstract class Validator
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_FLOAT = 'double'; /* gettype() returns 'double' for historical reasons */

    public const TYPE_STRING = 'string';

    public const TYPE_ARRAY = 'array';

    public const TYPE_OBJECT = 'object';

    public const TYPE_MIXED = 'mixed';

    /**
     * Get Description
     *
     * Returns validator description
     */
    abstract public function getDescription(): string;

    /**
     * Is array
     *
     * Returns true if an array or false if not.
     */
    abstract public function isArray(): bool;

    /**
     * Is valid
     *
     * Returns true if valid or false if not.
     *
     * @param  mixed  $value
     */
    abstract public function isValid($value): bool;

    /**
     * Cast a value that has already passed {@see isValid()} into the
     * canonical PHP type advertised by {@see getType()}.
     *
     * Loose validators accept query-string forms (for example `"false"` and
     * `"1"`) that PHP would otherwise coerce incorrectly when the action
     * parameter is typed. Override this in subclasses that accept such forms.
     *
     * Named `cast()` rather than `parse()` so subclasses that already expose
     * a static `parse()` helper (for example Appwrite's `CompoundUID`) do not
     * collide with this instance method.
     */
    public function cast(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    abstract public function getType(): string;
}
