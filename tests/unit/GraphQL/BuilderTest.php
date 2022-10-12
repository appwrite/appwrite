<?php

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

class BuilderTest extends TestCase
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
        $model = $this->response->getModel(Response::MODEL_COLLECTION);
        $type = Mapper::fromResponseModel(\ucfirst($model->getType()));
        $this->assertEquals('Collection', $type->name);
    }
}
