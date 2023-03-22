<?php

namespace Appwrite\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Utopia\Validator;
use Utopia\Database\Query;

class Queries extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Invalid queries';

    /**
     * @var Base[]
     */
    protected $validators;

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
                    if (\str_contains($query->getAttribute(), '.')) { // todo: double check!
                        return true;
                    }
                } catch (\Throwable $th) {
                    $this->message = "Invalid query: {$query}";
                    return false;
                }
            }

            $method = $query->getMethod();
            $methodType = '';
            switch ($method) {
                case Query::TYPE_SELECT:
                    $methodType = Base::METHOD_TYPE_SELECT;
                    break;
                case Query::TYPE_LIMIT:
                    $methodType = Base::METHOD_TYPE_LIMIT;
                    break;
                case Query::TYPE_OFFSET:
                    $methodType = Base::METHOD_TYPE_OFFSET;
                    break;
                case Query::TYPE_CURSORAFTER:
                case Query::TYPE_CURSORBEFORE:
                    $methodType = Base::METHOD_TYPE_CURSOR;
                    break;
                case Query::TYPE_ORDERASC:
                case Query::TYPE_ORDERDESC:
                    $methodType = Base::METHOD_TYPE_ORDER;
                    break;
                case Query::TYPE_EQUAL:
                case Query::TYPE_NOTEQUAL:
                case Query::TYPE_LESSER:
                case Query::TYPE_LESSEREQUAL:
                case Query::TYPE_GREATER:
                case Query::TYPE_GREATEREQUAL:
                case Query::TYPE_SEARCH:
                    $methodType = Base::METHOD_TYPE_FILTER;
                    break;
                default:
                    break;
            }

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
