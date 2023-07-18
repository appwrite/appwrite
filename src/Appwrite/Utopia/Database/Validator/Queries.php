<?php

namespace Appwrite\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Utopia\Http\Validator;
use Utopia\Database\Query;

class Queries extends Validator
{
    /**
     * @var string
     */
    protected string $message = 'Invalid queries';

    /**
     * @var array<Base>
     */
    protected array $validators;

    /**
     * Queries constructor
     *
     * @param Base ...$validators a list of validators
     */
    public function __construct(Base ...$validators)
    {
        $this->validators = $validators;
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
     * Returns false if:
     * 1. any query in $value is invalid based on $validator
     *
     * Otherwise, returns true.
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        foreach ($value as $query) {
            if (!$query instanceof Query) {
                try {
                    $query = Query::parse($query);
                } catch (\Throwable) {
                    $this->message = "Invalid query: {$query}";
                    return false;
                }
            }

            $method = $query->getMethod();
            $methodType = match ($method) {
                Query::TYPE_SELECT => Base::METHOD_TYPE_SELECT,
                Query::TYPE_LIMIT => Base::METHOD_TYPE_LIMIT,
                Query::TYPE_OFFSET => Base::METHOD_TYPE_OFFSET,
                Query::TYPE_CURSORAFTER,
                Query::TYPE_CURSORBEFORE => Base::METHOD_TYPE_CURSOR,
                Query::TYPE_ORDERASC,
                Query::TYPE_ORDERDESC => Base::METHOD_TYPE_ORDER,
                Query::TYPE_EQUAL,
                Query::TYPE_NOTEQUAL,
                Query::TYPE_LESSER,
                Query::TYPE_LESSEREQUAL,
                Query::TYPE_GREATER,
                Query::TYPE_GREATEREQUAL,
                Query::TYPE_SEARCH,
                Query::TYPE_IS_NULL,
                Query::TYPE_IS_NOT_NULL,
                Query::TYPE_BETWEEN,
                Query::TYPE_STARTS_WITH,
                Query::TYPE_ENDS_WITH => Base::METHOD_TYPE_FILTER,
                default => '',
            };

            $methodIsValid = false;
            foreach ($this->validators as $validator) {
                if ($validator->getMethodType() !== $methodType) {
                    continue;
                }
                if (!$validator->isValid($query)) {
                    $this->message = 'Query not valid: ' . $validator->getDescription();
                    return false;
                }

                $methodIsValid = true;
            }

            if (!$methodIsValid) {
                $this->message = 'Query method not valid: ' . $method;
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
        return true;
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
        return self::TYPE_OBJECT;
    }
}
