<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use GraphQL\Type\Definition\NamedType;
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

    /**
     * @throws \ReflectionException
     */
    public function testEnumAttributeResolvesToEnumModel(): void
    {
        $method = new \ReflectionMethod(Mapper::class, 'getColumnImplementation');

        $type = $method->invokeArgs(null, [['type' => 'string', 'format' => APP_DATABASE_ATTRIBUTE_ENUM], false]);

        $this->assertInstanceOf(NamedType::class, $type);
        $this->assertEquals('AttributeEnum', $type->name());
    }

    /**
     * @throws \ReflectionException
     */
    public function testEnumColumnResolvesToEnumModel(): void
    {
        $method = new \ReflectionMethod(Mapper::class, 'getColumnImplementation');

        $type = $method->invokeArgs(null, [['type' => 'string', 'format' => APP_DATABASE_ATTRIBUTE_ENUM], true]);

        $this->assertInstanceOf(NamedType::class, $type);
        $this->assertEquals('ColumnEnum', $type->name());
    }

    /**
     * @throws \ReflectionException
     */
    public function testLegacyEnumTypeResolvesToEnumAttribute(): void
    {
        $method = new \ReflectionMethod(Mapper::class, 'getColumnImplementation');

        $type = $method->invokeArgs(null, [['type' => 'enum'], false]);

        $this->assertInstanceOf(NamedType::class, $type);
        $this->assertEquals('AttributeEnum', $type->name());
    }

    /**
     * @throws \ReflectionException
     */
    public function testLegacyEnumTypeResolvesToEnumColumn(): void
    {
        $method = new \ReflectionMethod(Mapper::class, 'getColumnImplementation');

        $type = $method->invokeArgs(null, [['type' => 'enum'], true]);

        $this->assertInstanceOf(NamedType::class, $type);
        $this->assertEquals('ColumnEnum', $type->name());
    }
}
