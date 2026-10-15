<?php

namespace Appwrite\Utopia\Database\Validator\Query;

use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Base;
use Utopia\Database\Validator\Query\Joined\Collection;
use Utopia\Query\Method;

/**
 * Rejects ordering by a relationship whose related ids live on the other collection, or in a junction
 * table, so this collection has no column to sort on.
 */
class Order extends Base
{
    /**
     * @var array<string, true>
     */
    private array $columnless = [];

    /**
     * @param array<Document> $attributes
     */
    public function __construct(array $attributes)
    {
        foreach (Collection::columns($attributes) as $key => $holdsColumn) {
            if (!$holdsColumn) {
                $this->columnless[$key] = true;
            }
        }
    }

    protected function isValidQuery(Query $query): bool
    {
        if (!\in_array($query->getMethod(), [Method::OrderAsc, Method::OrderDesc], true)) {
            $this->message = 'Invalid query method: ' . $query->getMethod()->value;
            return false;
        }

        $attribute = $query->getAttribute();

        if (isset($this->columnless[$attribute])) {
            $this->message = 'Invalid query: Cannot order by relationship attribute: ' . $attribute;
            return false;
        }

        return true;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_ORDER;
    }
}
