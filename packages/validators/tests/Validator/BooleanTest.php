<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class BooleanTest extends TestCase
{
    public function testCanValidateStrictly(): void
    {
        $boolean = new Boolean();

        $this->assertTrue($boolean->isValid(true));
        $this->assertTrue($boolean->isValid(false));
        $this->assertFalse($boolean->isValid('false'));
        $this->assertFalse($boolean->isValid('true'));
        $this->assertFalse($boolean->isValid('0'));
        $this->assertFalse($boolean->isValid('1'));
        $this->assertFalse($boolean->isValid(0));
        $this->assertFalse($boolean->isValid(1));
        $this->assertFalse($boolean->isValid(['string', 'string']));
        $this->assertFalse($boolean->isValid('string'));
        $this->assertFalse($boolean->isValid(1.2));
        $this->assertFalse($boolean->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_BOOLEAN, $boolean->getType());
    }

    public function testCanValidateLoosely(): void
    {
        $boolean = new Boolean(true);

        $this->assertTrue($boolean->isValid(true));
        $this->assertTrue($boolean->isValid(false));
        $this->assertTrue($boolean->isValid('false'));
        $this->assertTrue($boolean->isValid('true'));
        $this->assertTrue($boolean->isValid('0'));
        $this->assertTrue($boolean->isValid('1'));
        $this->assertTrue($boolean->isValid(0));
        $this->assertTrue($boolean->isValid(1));
        $this->assertFalse($boolean->isValid(['string', 'string']));
        $this->assertFalse($boolean->isValid('string'));
        $this->assertFalse($boolean->isValid(1.2));
        $this->assertFalse($boolean->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_BOOLEAN, $boolean->getType());
    }
}
