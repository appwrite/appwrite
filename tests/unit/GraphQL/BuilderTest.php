<?php

namespace Appwrite\Tests;

use Appwrite\Event\Event;
use Appwrite\GraphQL\SchemaBuilder;
use Appwrite\GraphQL\TypeRegistry;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;

class BuilderTest extends TestCase
{
    protected ?Response $response = null;

    public function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        TypeRegistry::init($this->response->getModels());
    }

    /**
     * @throws \Exception
     */
    public function testCreateTypeMapping()
    {
        $model = $this->response->getModel(Response::MODEL_COLLECTION);
        $typeMapping = TypeRegistry::fromModel($model->getType());
    }
}
