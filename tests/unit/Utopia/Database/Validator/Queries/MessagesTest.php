<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Messages;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

final class MessagesTest extends TestCase
{
    public function testIsValid(): void
    {
        $validator = new Messages();

        /**
         * Test for Success
         */
        $this->assertEquals(true, $validator->isValid([Query::contains('users', ['someUserId'])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::contains('targets', ['someTargetId'])]), $validator->getDescription());

        /**
         * Test for Failure
         */
        $this->assertEquals(false, $validator->isValid([Query::contains('topics', ['someTopicId'])]), $validator->getDescription());
    }
}
