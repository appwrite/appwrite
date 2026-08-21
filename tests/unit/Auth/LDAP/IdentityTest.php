<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\LDAP;

use Appwrite\Auth\LDAP\Exception;
use Appwrite\Auth\LDAP\Identity;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    public function testIdentityExposesDirectoryValues(): void
    {
        $identity = new Identity('uid=alice,ou=people,dc=example,dc=com', 'alice@example.com', 'Alice Smith');

        $this->assertSame('uid=alice,ou=people,dc=example,dc=com', $identity->getDn());
        $this->assertSame('alice@example.com', $identity->getEmail());
        $this->assertSame('Alice Smith', $identity->getName());
    }

    public function testEmailIsNormalized(): void
    {
        $identity = new Identity('uid=alice', '  Alice@Example.COM  ', '  Alice  ');

        $this->assertSame('alice@example.com', $identity->getEmail());
        $this->assertSame('Alice', $identity->getName());
    }

    /**
     * An account cannot be created without an email, so this has to fail loudly
     * with a message naming the fix rather than producing a broken user.
     */
    public function testMissingEmailIsRejectedWithActionableMessage(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/email attribute mapping/i');

        new Identity('uid=alice', '');
    }

    public function testMalformedEmailIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/not a valid email/i');

        new Identity('uid=alice', 'not-an-email');
    }

    /**
     * The display name is optional: a directory entry without one is still a
     * usable account.
     */
    public function testNameIsOptional(): void
    {
        $this->assertSame('', (new Identity('uid=alice', 'alice@example.com'))->getName());
    }
}
