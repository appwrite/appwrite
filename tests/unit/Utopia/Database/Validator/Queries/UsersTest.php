<?php

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Users;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

class UsersTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testIsValid(): void
    {
        $validator = new Users();

        /**
         * Test for Success
         */
        $this->assertEquals(true, $validator->isValid([]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('name', ['value'])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('email', ['value'])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('phone', ['value'])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::greaterThan('passwordUpdate', '2020-10-15 06:38')]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::greaterThan('registration', '2020-10-15 06:38')]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('emailVerification', [true])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('phoneVerification', [true])]), $validator->getDescription());

        /**
         * Test for Failure
         */
        $this->assertEquals(false, $validator->isValid([Query::equal('password', ['value'])]), $validator->getDescription());
    }
}
