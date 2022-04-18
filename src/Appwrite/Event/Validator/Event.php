<?php

namespace Appwrite\Event\Validator;

use Utopia\Validator;

class Event extends Validator
{
    protected array $types = [
        'users' => [
            'subTypes' => [
                'sessions',
                'recovery',
                'verification'
            ],
            'attributes' => [
                'email',
                'name',
                'password',
                'status',
                'prefs',
            ]
        ],
        'collections' => [
            'subTypes' => [
                'documents',
                'attributes',
                'indexes'
            ]
        ],
        'buckets' => [
            'subTypes' => [
                'files'
            ]
        ],
        'teams' => [
            'subTypes' => [
                'memberships' => [
                    'attributes' => [
                        'status'
                    ]
                ]
            ]
        ],
        'functions' => [
            'subTypes' => [
                'deployments',
                'executions'
            ]
        ],
    ];

    protected array $actions = [
        'create',
        'update',
        'delete'
    ];

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
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        $parts = \explode('.', $value);
        $count = \count($parts);

        if ($count < 2 || $count > 6) {
            return false;
        }

        /**
         * Identify all sections of the pattern.
         */
        $type = $parts[0] ?? false;
        $resource = $parts[1] ?? false;
        $hasSubResource = $count > 3 && \array_key_exists('subTypes', $this->types[$type]) && \in_array($parts[2], $this->types[$type]['subTypes']);

        if (!$type || !$resource) {
            return false;
        }

        if ($hasSubResource) {
            $subType = $parts[2];
            $subResource = $parts[3];
            if ($count === 6) {
                $attribute = $parts[5];
            }
        } else {
            if ($count === 4) {
                $attribute = $parts[3];
            }
        }

        $subType ??= false;
        $subResource ??= false;
        $attribute ??= false;

        $action = match (true) {
            !$hasSubResource && $count > 2 => $parts[2],
            $hasSubResource && $count > 4 => $parts[4],
            default => false
        };

        if ($action && !\in_array($action, $this->actions)) {
            return false;
        }

        if (!\in_array($type, \array_keys($this->types))) {
            return false;
        }

        if ($subtype ?? false) {
            if (!($subResource ?? false) || !\in_array($subType, $this->types[$type]['subTypes'])) {
                return false;
            }
        }

        if ($attribute ?? false) {
            if (
                (\array_key_exists('attributes', $this->types[$type]) && !\in_array($attribute, $this->types[$type]['attributes'])) ||
                (($subType ?? false) && \array_key_exists('attributes', $this->types[$type]['subTypes'][$subType]) && !\in_array($attribute, $this->types[$type]['subTypes'][$subType]['attributes']))
            ) {
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
        return self::TYPE_STRING;
    }
}
