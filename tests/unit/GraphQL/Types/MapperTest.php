<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL\Types;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\Utopia\Response\Model\Any;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase
{
    #[DataProvider('additionalDataProvider')]
    public function testSerializeAdditionalData(array $data, string $expected): void
    {
        Mapper::init(['any' => new Any()]);

        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'record' => ['type' => Mapper::model('Any')],
                ],
            ]),
        ]);

        $result = GraphQL::executeQuery($schema, '{ record { data } }', ['record' => $data])->toArray();

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame($expected, $result['data']['record']['data']);
    }

    public static function additionalDataProvider(): \Iterator
    {
        yield 'string array' => [
            ['tags' => ['first', 'second']],
            '{"tags":["first","second"]}',
        ];
        yield 'empty array' => [
            ['tags' => []],
            '{"tags":[]}',
        ];
        yield 'numeric and boolean arrays' => [
            ['numbers' => [0, 1, 2.5], 'flags' => [true, false]],
            '{"numbers":[0,1,2.5],"flags":[true,false]}',
        ];
        yield 'nested arrays and objects' => [
            [
                'records' => [['tags' => ['first']], ['tags' => []]],
                'settings' => (object)['flags' => [true]],
                'empty' => (object)[],
            ],
            '{"records":[{"tags":["first"]},{"tags":[]}],"settings":{"flags":[true]},"empty":{}}',
        ];
        yield 'empty data is an object' => [
            [],
            '{}',
        ];
        yield 'system fields are excluded' => [
            ['_id' => 'record', '_permissions' => ['read("any")'], 'tags' => ['first']],
            '{"tags":["first"]}',
        ];
        yield 'only system fields' => [
            ['_id' => 'record'],
            '{}',
        ];
        yield 'numeric attribute names keep outer object' => [
            [0 => ['first'], 1 => []],
            '{"0":["first"],"1":[]}',
        ];
        yield 'scalar attributes' => [
            ['name' => 'record', 'count' => 0, 'enabled' => false, 'optional' => null],
            '{"name":"record","count":0,"enabled":false,"optional":null}',
        ];
    }
}
