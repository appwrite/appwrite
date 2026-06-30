<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\Utopia\Response;
use GraphQL\Type\Definition\NamedType;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

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
