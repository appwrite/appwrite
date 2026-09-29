<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Index;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\Order;

final class DatabaseSchemaTest extends TestCase
{
    public function testAttributesDescribeTheAbuseCollection(): void
    {
        $this->assertSame(
            [
                ['key' => 'key', 'type' => ColumnType::String, 'size' => Database::LENGTH_KEY, 'required' => true, 'default' => null, 'signed' => true, 'array' => false, 'format' => null, 'filters' => []],
                ['key' => 'time', 'type' => ColumnType::Datetime, 'size' => 0, 'required' => true, 'default' => null, 'signed' => false, 'array' => false, 'format' => null, 'filters' => ['datetime']],
                ['key' => 'count', 'type' => ColumnType::Integer, 'size' => 11, 'required' => true, 'default' => null, 'signed' => false, 'array' => false, 'format' => null, 'filters' => []],
            ],
            \array_map(self::describeAttribute(...), TimeLimit\Database::attributes()),
        );
    }

    public function testIndexesDescribeTheAbuseCollection(): void
    {
        $this->assertSame(
            [
                ['key' => 'unique1', 'type' => IndexType::Unique, 'attributes' => ['key', 'time'], 'lengths' => [], 'orders' => []],
                ['key' => 'index2', 'type' => IndexType::Key, 'attributes' => ['time'], 'lengths' => [], 'orders' => []],
            ],
            \array_map(self::describeIndex(...), TimeLimit\Database::indexes()),
        );
    }

    public function testSetupCreatesTheCollectionTheAccessorsDescribe(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database->setDatabase('abuse')->setNamespace('schema');
        $database->create();

        new TimeLimit\Database('', 1, 1, $database)->setup();

        $collection = $database->getCollection(TimeLimit\Database::COLLECTION);

        $this->assertSame(
            \array_map(self::describeAttribute(...), TimeLimit\Database::attributes()),
            \array_map(self::describeAttribute(...), $collection->attributes),
        );
        $this->assertSame(
            \array_map(self::describeIndex(...), TimeLimit\Database::indexes()),
            \array_map(self::describeIndex(...), $collection->indexes),
        );
    }

    public function testChangingReturnedDefinitionsLeavesTheSchemaIntact(): void
    {
        $attributes = TimeLimit\Database::attributes();
        $attributes[0]->size = 1;
        $indexes = TimeLimit\Database::indexes();
        $indexes[0]->attributes = ['key'];

        $this->assertSame(Database::LENGTH_KEY, TimeLimit\Database::attributes()[0]->size);
        $this->assertSame(['key', 'time'], TimeLimit\Database::indexes()[0]->attributes);
    }

    /**
     * @return array{key: string, type: ColumnType, size: int, required: bool, default: mixed, signed: bool, array: bool, format: string|null, filters: array<string>}
     */
    private static function describeAttribute(Attribute $attribute): array
    {
        return [
            'key' => $attribute->key,
            'type' => $attribute->type,
            'size' => $attribute->size,
            'required' => $attribute->required,
            'default' => $attribute->default,
            'signed' => $attribute->signed,
            'array' => $attribute->array,
            'format' => $attribute->format,
            'filters' => $attribute->filters,
        ];
    }

    /**
     * @return array{key: string, type: IndexType, attributes: array<string>, lengths: array<int|null>, orders: array<Order|null>}
     */
    private static function describeIndex(Index $index): array
    {
        return [
            'key' => $index->key,
            'type' => $index->type,
            'attributes' => $index->attributes,
            'lengths' => $index->lengths,
            'orders' => $index->orders,
        ];
    }
}
