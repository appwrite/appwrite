<?php

namespace Tests\Unit\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Appwrite\Utopia\Database\Validator\Query\Order;
use Appwrite\Utopia\Database\Validator\Query\Select;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
        $this->validator = new Select(
            attributes: [
                new Document([
                    'key' => 'attr',
                    'type' => Database::VAR_STRING,
                    'array' => false,
                ]),
            ],
        );
    }

    public function tearDown(): void
    {
    }

    public function testValue(): void
    {
        // Test for Success
        $this->assertEquals($this->validator->isValid(Query::select(['*', 'attr'])), true, $this->validator->getDescription());

        // Test for Failure
        $this->assertEquals($this->validator->isValid(Query::limit(1)), false, $this->validator->getDescription());
    }
}
