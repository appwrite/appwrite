<?php

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\GraphQL\Types\Registry;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

class BuilderTest extends TestCase
{
    protected ?Response $response = null;
    protected ?Mapper $mapper = null;

    public function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        $registry = new Registry('test-project');
        $this->mapper = new Mapper($registry, $this->response->getModels());
    }

    /**
     * @throws \Exception
     */
    public function testCreateTypeMapping()
    {
        $model = $this->response->getModel(Response::MODEL_TABLE);
        $type = $this->mapper->model(\ucfirst($model->getType()));
        $this->assertEquals('Table', $type->name);
    }
}
