<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;
use Utopia\Database\Document;
use Utopia\Database\Validator\Query as QueryValidator;
use Utopia\Database\Query;

class Queries extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Invalid queries';

    /**
     * @var QueryValidator
     */
    protected $validator;

    /**
     * Queries constructor
     *
     * @param $validators - a list of validators
     */
    public function __construct(Limit $limit = null, Offset $offset = null, Order $order = null, Cursor $cursor = null, Filter $filter = null)
    {
        $this->limit = $limit;
        $this->offset = $offset;
        $this->order = $order;
        $this->filter = $filter;
        $this->cursor = $cursor;
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
        $queries = [];
        foreach ($value as $query) {
            if (!$query instanceof Query) {
                try {
                    $query = Query::parse($query);
                } catch (\Throwable $th) {
                    $this->message = 'Invalid query: ${query}';
                    return false;
                }
            }

            $method = $query->getMethod();
            switch ($method) {
                case Query::TYPE_LIMIT:
                    $validator = $this->limit;
                case Query::TYPE_OFFSET:
                    $validator = $this->offset;
                case Query::TYPE_ORDER:
                    $validator = $this->order;
                default:
                    return false;
            }
            
            if ($validator && !$validator->isValid($query)) {
                $this->message = 'Query not valid: ' . $this->validator->getDescription();
                return false;
            }

            $queries[] = $query;
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
