<?php

namespace Appwrite\Tests;

use Appwrite\Event\Event;
use Appwrite\GraphQL\Builder;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;

class BuilderTest extends TestCase
{

    
    /**
     * @var Response
     */
    protected $response = null;

    public function setUp(): void
    {
        $this->response = new Response(new SwooleResponse());
        Builder::init();
    }

    public function testCreateTypeMapping() 
    {
        $model = $this->response->getModel(Response::MODEL_COLLECTION);
        $typeMapping = Builder::getTypeMapping($model, $this->response);
    }

}