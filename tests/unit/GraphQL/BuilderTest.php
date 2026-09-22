<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\DI\Container;
use Utopia\Http\Adapter\FPM\Server;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\Validator\Text;

final class BuilderTest extends TestCase
{
    protected ?Response $response = null;

    public function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        Mapper::init($this->response->getModels());
    }

    /**
     * @throws \Exception
     */
    public function testCreateTypeMapping()
    {
        $model = $this->response->getModel(Response::MODEL_TABLE);
        $type = Mapper::model(\ucfirst($model->getType()));
        $this->assertInstanceOf(NamedType::class, $type);
        $this->assertSame('Table', $type->name());
    }

    #[DataProvider('additionalDataProvider')]
    public function testSerializeAdditionalData(array $data, string $expected): void
    {
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
        $actual = json_decode($result['data']['record']['data'], false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $actual);
        $this->assertEquals(json_decode($expected, false, flags: JSON_THROW_ON_ERROR), $actual);
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

    public function testRouteOmitsHiddenParameters(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $method = new Method(
            namespace: 'test',
            group: null,
            name: 'createGraphQLHiddenTest',
            description: 'Create test.',
            auth: [],
            responses: [
                new SDKResponse(code: 201, model: Response::MODEL_ANY),
            ],
            parameters: [
                new Parameter('engine', hide: true),
            ],
        );

        $route = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->param('name', '', new Text(128), 'Name.')
            ->param('engine', 'mysql', new Text(16), 'Engine.', true);

        $fields = \iterator_to_array($this->mapRoute($route, $method));

        $this->assertCount(1, $fields);
        $this->assertArrayHasKey('name', $fields[0]['args']);
        $this->assertArrayNotHasKey('engine', $fields[0]['args']);
    }

    public function testRouteParameterWhitelistStillApplies(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $method = new Method(
            namespace: 'test',
            group: null,
            name: 'createGraphQLWhitelistTest',
            description: 'Create test.',
            auth: [],
            responses: [
                new SDKResponse(code: 201, model: Response::MODEL_ANY),
            ],
            parameters: [
                new Parameter('name', optional: false),
            ],
        );

        $route = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->param('name', '', new Text(128), 'Name.')
            ->param('engine', 'mysql', new Text(16), 'Engine.', true);

        $fields = \iterator_to_array($this->mapRoute($route, $method));

        $this->assertCount(1, $fields);
        $this->assertSame(['name'], \array_keys($fields[0]['args']));
    }

    private function mapRoute(Route $route, Method $method): iterable
    {
        return Mapper::route(
            new Http(new Server(new Container()), 'UTC'),
            $route,
            $method,
            'POST',
            static fn () => 1,
        );
    }
}
