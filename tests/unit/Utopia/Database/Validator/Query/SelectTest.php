<?php

namespace Tests\Unit\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Appwrite\Utopia\Database\Validator\Query\Select;
use Utopia\Database\Query;
use PHPUnit\Framework\TestCase;

class SelectTest extends TestCase
{
    /**
     * @var Base
     */
    protected $validator = null;

    public function setUp(): void
    {
        $this->validator = new Select();
    }

    public function tearDown(): void
    {
    }

    public function testValue(): void
    {
        // Test for Success
        $this->assertEquals($this->validator->isValid(Query::select(['*', 'attr1', 'attr2', 'collection.id'])), true, $this->validator->getDescription());

        // Test for Failure
        $this->assertEquals($this->validator->isValid(Query::limit(1)), false, $this->validator->getDescription());
    }
}
