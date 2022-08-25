<?php

namespace Appwrite\Tests;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V11;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

class ResponseTest extends TestCase
{
    /**
     * @var Response
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Response(new SwooleResponse());
    }

    public function testSetFilter()
    {
        $this->assertEquals(false, $this->object->hasFilter());
        $this->assertEquals(null, $this->object->getFilter());

        $filter = new V11();
        $this->object->setFilter($filter);

        $this->assertEquals(true, $this->object->hasFilter());
        $this->assertEquals($this->object->getFilter(), $filter);
    }
}
