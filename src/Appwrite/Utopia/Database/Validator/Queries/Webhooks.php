<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Webhooks extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'url',
        'authUsername',
        'tls',
        'events',
        'enabled',
        'logs',
        'attempts',
    ];

    /**
     * Map API attribute names to DB column names.
     */
    private const ATTRIBUTE_ALIASES = [
        'tls' => 'security',
        'authUsername' => 'httpUser',
    ];

    /**
     * DB column names used for schema validation.
     */
    private const DB_ATTRIBUTES = [
        'name',
        'url',
        'httpUser',
        'security',
        'events',
        'enabled',
        'logs',
        'attempts',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('webhooks', self::DB_ATTRIBUTES);
    }

    /**
     * Convert API attribute names to DB column names in query strings before validation.
     */
    public function isValid($value): bool
    {
        if (\is_array($value)) {
            foreach ($value as &$queryString) {
                if (!\is_string($queryString)) {
                    continue;
                }
                foreach (self::ATTRIBUTE_ALIASES as $alias => $dbName) {
                    $queryString = \str_replace('"' . $alias . '"', '"' . $dbName . '"', $queryString);
                }
            }
            unset($queryString);
        }

        return parent::isValid($value);
    }
}
