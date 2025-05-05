<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\IndexedQueries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;
use Utopia\Database\Validator\Query\Select;
use Utopia\Validator;

class DocumentValidator extends Validator
{
    protected Validator $additionalValidator;
    protected int $length;

    public function __construct(Validator $additionalValidator, int $length)
    {
        $this->additionalValidator = $additionalValidator;
        $this->length = $length;
    }

    public function getDescription(): string
    {
        return 'Validates document queries and checks if they conform to the required rules, along with additional validation rules.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return 'DocumentValidator';
    }

    public function isValid(mixed $values): bool
    {
        if (!\is_array($values)) {
            return false;
        }
        $queries = Query::parseQueries($values);
        if (!$this->additionalValidator->isValid($values)) {
            return false;
        }

        $attributes[] = new Document([
            '$id' => '$internalId',
            'key' => '$internalId',
            'type' => Database::VAR_STRING,
            'array' => false,
        ]);

        $validators = [
            new Limit(),
            new Offset(),
            new Cursor(),
            new Filter(
                $attributes,
                $this->length,
                new \DateTime('0000-01-01'),
                new \DateTime('9999-12-31')
            ),
            new Order($attributes),
            new Select($attributes),
        ];

        $indexedQueries = new IndexedQueries(
            $attributes,
            [],
            $validators
        );
        // the queries should not contain internalId
        if ($indexedQueries->isValid($queries)) {
            return false;
        }

        return true;
    }
}
