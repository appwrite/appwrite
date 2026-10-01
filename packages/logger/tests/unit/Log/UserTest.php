<?php

namespace Utopia\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Utopia\Logger\Log\User;

class UserTest extends TestCase
{
    public function testLogUser(): void
    {
        $user = new User();

        self::assertEquals(null, $user->getEmail());
        self::assertEquals(null, $user->getUsername());
        self::assertEquals(null, $user->getId());

        $user = new User('618e291cd8949');
        self::assertEquals('618e291cd8949', $user->getId());

        $user = new User(null, 'matej@appwrite.io');
        self::assertEquals('matej@appwrite.io', $user->getEmail());

        $user = new User(null, null, 'Meldiron');
        self::assertEquals('Meldiron', $user->getUsername());
    }
}
