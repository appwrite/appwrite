<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Base as QueryValidator;

/**
 * Implements {@see Restricted} for classes extending Utopia's Queries validator.
 *
 * Methods are derived from the composed sub-validators, so they cannot drift from
 * what the validator actually accepts. A sub-validator that restricts its own
 * methods or attributes implements {@see Restricted} itself.
 *
 * @property array<QueryValidator> $validators
 */
trait RestrictsQueries
{
    /**
     * @var string[]|null
     */
    protected ?array $allowedAttributes = null;

    /**
     * @return string[]|null
     */
    public function getAllowedAttributes(): ?array
    {
        $attributes = $this->allowedAttributes;

        foreach ($this->validators as $validator) {
            if ($validator instanceof Restricted && $validator->getAllowedAttributes() !== null) {
                $attributes = \array_merge($attributes ?? [], $validator->getAllowedAttributes());
            }
        }

        return $attributes === null ? null : \array_values(\array_unique($attributes));
    }

    /**
     * @return string[]
     */
    public function getAllowedMethods(): array
    {
        $methods = [];

        foreach ($this->validators as $validator) {
            if ($validator instanceof Restricted) {
                $methods = \array_merge($methods, $validator->getAllowedMethods());
                continue;
            }

            $methods = \array_merge($methods, match ($validator->getMethodType()) {
                QueryValidator::METHOD_TYPE_LIMIT => [Query::TYPE_LIMIT],
                QueryValidator::METHOD_TYPE_OFFSET => [Query::TYPE_OFFSET],
                QueryValidator::METHOD_TYPE_CURSOR => [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE],
                QueryValidator::METHOD_TYPE_ORDER => [Query::TYPE_ORDER_ASC, Query::TYPE_ORDER_DESC],
                QueryValidator::METHOD_TYPE_SELECT => [Query::TYPE_SELECT],
                // Filter validates per attribute type, not per method, so every filter method is listed.
                QueryValidator::METHOD_TYPE_FILTER => self::FILTER_METHODS,
                default => [],
            });
        }

        return \array_values(\array_unique($methods));
    }

    /**
     * @var string[]
     */
    private const FILTER_METHODS = [
        Query::TYPE_EQUAL,
        Query::TYPE_NOT_EQUAL,
        Query::TYPE_LESSER,
        Query::TYPE_LESSER_EQUAL,
        Query::TYPE_GREATER,
        Query::TYPE_GREATER_EQUAL,
        Query::TYPE_SEARCH,
        Query::TYPE_NOT_SEARCH,
        Query::TYPE_IS_NULL,
        Query::TYPE_IS_NOT_NULL,
        Query::TYPE_BETWEEN,
        Query::TYPE_NOT_BETWEEN,
        Query::TYPE_STARTS_WITH,
        Query::TYPE_NOT_STARTS_WITH,
        Query::TYPE_ENDS_WITH,
        Query::TYPE_NOT_ENDS_WITH,
        Query::TYPE_CONTAINS,
        Query::TYPE_NOT_CONTAINS,
        Query::TYPE_AND,
        Query::TYPE_OR,
    ];
}