<?php

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Http\Adapter\Swoole\Response as UtopiaSwooleResponse;

class BuilderTest extends TestCase
{
    protected ?Response $response = null;

    public function setUp(): void
    {
        Response\Models::init();
        $this->response = new Response(new UtopiaSwooleResponse(new SwooleResponse()));
        Mapper::init(Response\Models::getModels());
    }

    /**
     * @throws \Exception
     */
    public function testCreateTypeMapping()
    {
        $model = Response\Models::getModel(Response::MODEL_COLLECTION);
        $type = Mapper::model(\ucfirst($model->getType()));
        $this->assertEquals('Collection', $type->name);
    }
}
