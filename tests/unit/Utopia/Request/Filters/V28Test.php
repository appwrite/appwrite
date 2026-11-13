<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filters\V28;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V28Test extends TestCase
{
    private const string INCREMENT = '{"method":"increment","values":[1]}';

    public static function updates(): array
    {
        $models = [
            'databases.updateDocument',
            'databases.upsertDocument',
            'databases.updateDocuments',
            'tablesDB.updateRow',
            'tablesDB.upsertRow',
            'tablesDB.updateRows',
            'documentsDB.updateDocument',
            'documentsDB.upsertDocument',
            'documentsDB.updateDocuments',
            'vectorsDB.updateDocument',
            'vectorsDB.upsertDocument',
            'vectorsDB.updateDocuments',
        ];

        return \array_combine($models, \array_map(fn (string $model) => [$model], $models));
    }

    #[DataProvider('updates')]
    public function testWrapsLegacyOperatorStrings(string $model): void
    {
        $filter = new V28();

        $result = $filter->parse([
            'data' => [
                'count' => self::INCREMENT,
                'title' => 'banana',
            ],
        ], $model);

        $this->assertSame([
            'data' => [
                'count' => ['$operator' => ['method' => 'increment', 'values' => [1]]],
                'title' => 'banana',
            ],
        ], $result);
    }

    public function testKeepsEveryOperatorKey(): void
    {
        $filter = new V28();

        $result = $filter->parse([
            'data' => ['title' => ' {"values":["a",""],"method":"stringReplace","extra":true} '],
        ], 'tablesDB.updateRow');

        $this->assertSame([
            'data' => ['title' => ['$operator' => ['values' => ['a', ''], 'method' => 'stringReplace', 'extra' => true]]],
        ], $result);
    }

    public function testLeavesLiteralStringsAlone(): void
    {
        $filter = new V28();
        $data = [
            'plain' => 'banana',
            'number' => '42',
            'broken' => '{"method":"increment"',
            'list' => '["increment"]',
            'unknown' => '{"method":"explode","values":[1]}',
            'nonString' => '{"method":1,"values":[1]}',
            'noMethod' => '{"values":[1]}',
            'count' => 5,
            'tags' => [self::INCREMENT],
        ];

        $this->assertSame(['data' => $data], $filter->parse(['data' => $data], 'tablesDB.updateRow'));
    }

    public function testLeavesSystemKeysAlone(): void
    {
        $filter = new V28();
        $data = ['$id' => self::INCREMENT, '$permissions' => ['read("any")']];

        $this->assertSame(['data' => $data], $filter->parse(['data' => $data], 'tablesDB.updateRow'));
    }

    public function testLeavesEnvelopesAlone(): void
    {
        $filter = new V28();
        $data = ['count' => ['$operator' => ['method' => 'increment', 'values' => [1]]]];

        $this->assertSame(['data' => $data], $filter->parse(['data' => $data], 'tablesDB.updateRow'));
    }

    public function testWrapsEveryUpsertedDocument(): void
    {
        $filter = new V28();
        $wrapped = ['$operator' => ['method' => 'increment', 'values' => [1]]];

        $this->assertSame(
            ['rows' => [['$id' => 'a', 'count' => $wrapped], ['$id' => 'b', 'title' => 'banana']]],
            $filter->parse(['rows' => [['$id' => 'a', 'count' => self::INCREMENT], ['$id' => 'b', 'title' => 'banana']]], 'tablesDB.upsertRows')
        );

        foreach (['databases.upsertDocuments', 'documentsDB.upsertDocuments', 'vectorsDB.upsertDocuments'] as $model) {
            $this->assertSame(
                ['documents' => [['$id' => 'a', 'count' => $wrapped]]],
                $filter->parse(['documents' => [['$id' => 'a', 'count' => self::INCREMENT]]], $model)
            );
        }
    }

    public function testWrapsStagedOperations(): void
    {
        $filter = new V28();
        $wrapped = ['$operator' => ['method' => 'increment', 'values' => [1]]];

        $result = $filter->parse([
            'operations' => [
                ['action' => 'update', 'rowId' => 'a', 'data' => ['count' => self::INCREMENT]],
                ['action' => 'upsert', 'rowId' => 'a', 'data' => ['count' => self::INCREMENT]],
                ['action' => 'bulkUpdate', 'data' => ['queries' => [], 'data' => ['count' => self::INCREMENT]]],
                ['action' => 'bulkUpsert', 'data' => [['$id' => 'a', 'count' => self::INCREMENT]]],
                ['action' => 'create', 'rowId' => 'b', 'data' => ['title' => self::INCREMENT]],
                ['action' => 'bulkCreate', 'data' => [['$id' => 'c', 'title' => self::INCREMENT]]],
                ['action' => 'increment', 'rowId' => 'a', 'data' => ['column' => 'count', 'value' => 1]],
                ['action' => 'delete', 'rowId' => 'a'],
            ],
        ], 'tablesDB.createOperations');

        $this->assertSame([
            'operations' => [
                ['action' => 'update', 'rowId' => 'a', 'data' => ['count' => $wrapped]],
                ['action' => 'upsert', 'rowId' => 'a', 'data' => ['count' => $wrapped]],
                ['action' => 'bulkUpdate', 'data' => ['queries' => [], 'data' => ['count' => $wrapped]]],
                ['action' => 'bulkUpsert', 'data' => [['$id' => 'a', 'count' => $wrapped]]],
                ['action' => 'create', 'rowId' => 'b', 'data' => ['title' => self::INCREMENT]],
                ['action' => 'bulkCreate', 'data' => [['$id' => 'c', 'title' => self::INCREMENT]]],
                ['action' => 'increment', 'rowId' => 'a', 'data' => ['column' => 'count', 'value' => 1]],
                ['action' => 'delete', 'rowId' => 'a'],
            ],
        ], $result);
    }

    public function testWrapsOperationsOfEveryDatabaseType(): void
    {
        $filter = new V28();
        $content = ['operations' => [['action' => 'update', 'documentId' => 'a', 'data' => ['count' => self::INCREMENT]]]];

        foreach (['databases.createOperations', 'documentsDB.createOperations', 'vectorsDB.createOperations'] as $model) {
            $this->assertSame(
                ['$operator' => ['method' => 'increment', 'values' => [1]]],
                $filter->parse($content, $model)['operations'][0]['data']['count']
            );
        }
    }

    public function testLeavesCreatesAlone(): void
    {
        $filter = new V28();
        $content = [
            'data' => ['title' => self::INCREMENT],
            'documents' => [['title' => self::INCREMENT]],
            'rows' => [['title' => self::INCREMENT]],
        ];

        foreach (['databases.createDocument', 'databases.createDocuments', 'tablesDB.createRow', 'tablesDB.createRows', 'documentsDB.createDocument', 'vectorsDB.createDocuments'] as $model) {
            $this->assertSame($content, $filter->parse($content, $model));
        }
    }

    public function testDecodesStringData(): void
    {
        $filter = new V28();

        $this->assertSame(
            ['data' => ['count' => ['$operator' => ['method' => 'increment', 'values' => [1]]], 'title' => 'banana']],
            $filter->parse(['data' => '{"count":' . \json_encode(self::INCREMENT) . ',"title":"banana"}'], 'tablesDB.updateRow')
        );

        $this->assertSame(
            ['data' => '{"title":"banana"}'],
            $filter->parse(['data' => '{"title":"banana"}'], 'tablesDB.updateRow')
        );
    }

    public function testIgnoresMalformedPayloads(): void
    {
        $filter = new V28();

        $this->assertSame(['data' => 'not json'], $filter->parse(['data' => 'not json'], 'tablesDB.updateRow'));
        $this->assertSame(['rows' => 'nope'], $filter->parse(['rows' => 'nope'], 'tablesDB.upsertRows'));
        $this->assertSame(['operations' => 'nope'], $filter->parse(['operations' => 'nope'], 'tablesDB.createOperations'));
        $this->assertSame(
            ['operations' => ['nope', ['action' => 'update']]],
            $filter->parse(['operations' => ['nope', ['action' => 'update']]], 'tablesDB.createOperations')
        );
        $this->assertSame([], $filter->parse([], 'tablesDB.updateRow'));
    }
}
