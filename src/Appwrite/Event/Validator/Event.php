<?php

namespace Appwrite\Event\Validator;

use Utopia\Config\Config;
use Utopia\Validator;

class Event extends Validator
{
    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Event is not valid.';
    }

    /**
     * Is valid.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isValid($value): bool
    {
        $events = Config::getParam('events', []);
        $parts = \explode('.', $value);
        $count = \count($parts);

        if ($count < 2 || $count > 7) {
            return false;
        }

        /**
         * Identify all sections of the pattern.
         */
        $type = $parts[0] ?? false;
        $resource = $parts[1] ?? false;
        $hasSubResource = $count > 3 && ($events[$type]['$resource'] ?? false) && ($events[$type][$parts[2]]['$resource'] ?? false);
        $hasSubSubResource = $count > 5 && $hasSubResource && ($events[$type][$parts[2]][$parts[4]]['$resource'] ?? false);

        if (! $type || ! $resource) {
            return false;
        }

        if ($hasSubResource) {
            $subType = $parts[2];
            $subResource = $parts[3];
        }

        if ($hasSubSubResource) {
            $subSubType = $parts[4];
            $subSubResource = $parts[5];
            if ($count === 8) {
                $attribute = $parts[7];
            }
        }

        if ($hasSubResource && ! $hasSubSubResource) {
            if ($count === 6) {
                $attribute = $parts[5];
            }
        }

        if (! $hasSubResource) {
            if ($count === 4) {
                $attribute = $parts[3];
            }
        }

        $subSubType ??= false;
        $subSubResource ??= false;
        $subType ??= false;
        $subResource ??= false;
        $attribute ??= false;

        $action = match (true) {
            ! $hasSubResource && $count > 2 => $parts[2],
            $hasSubSubResource => $parts[6] ?? false,
            $hasSubResource && $count > 4 => $parts[4],
            default => false
        };

        if (! \array_key_exists($type, $events)) {
            return false;
        }

        if ($subType) {
            if ($action && ! \array_key_exists($action, $events[$type][$subType])) {
                return false;
            }
            if (! ($subResource) || ! \array_key_exists($subType, $events[$type])) {
                return false;
            }
        } else {
            if ($action && ! \array_key_exists($action, $events[$type])) {
                return false;
            }
        }

        if ($attribute) {
            if (($subType)) {
                if (! \array_key_exists($attribute, $events[$type][$subType][$action])) {
                    return false;
                }
            } else {
                if (! \array_key_exists($attribute, $events[$type][$action])) {
                    return false;
                }
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
        return self::TYPE_STRING;
    }
}
