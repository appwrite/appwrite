<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class IdentifierTest extends TestCase
{
    public function testAcceptsValidIdentifiers(): void
    {
        $identifier = new Identifier();

        $this->assertTrue($identifier->isValid('APPWRITE_APP_SECRET'));
        $this->assertTrue($identifier->isValid('_private'));
        $this->assertTrue($identifier->isValid('lowercase_ok'));
        $this->assertTrue($identifier->isValid('A1'));

        $this->assertFalse($identifier->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $identifier->getType());
    }

    public function testRejectsNonIdentifiers(): void
    {
        $identifier = new Identifier();

        $this->assertFalse($identifier->isValid("APPWRITE_APP_SECRET\t"), 'trailing tab');
        $this->assertFalse($identifier->isValid("APPWRITE_APP_SECRET\n"), 'trailing newline');
        $this->assertFalse($identifier->isValid('GRAVAÇÃO'), 'accented letters');
        $this->assertFalse($identifier->isValid('9FOO'), 'leading digit');
        $this->assertFalse($identifier->isValid('MY-VAR'), 'hyphen');
        $this->assertFalse($identifier->isValid('MY VAR'), 'space');
        $this->assertFalse($identifier->isValid('FOO=BAR'), 'equals');
        $this->assertFalse($identifier->isValid(''), 'empty');
        $this->assertFalse($identifier->isValid(123), 'non-string');
    }

    public function testEnforcesLength(): void
    {
        $identifier = new Identifier(4);

        $this->assertTrue($identifier->isValid('ABCD'));
        $this->assertFalse($identifier->isValid('ABCDE'), 'over-length');
    }
}
