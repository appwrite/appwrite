<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class WildcardTest extends TestCase
{
    public function testCanValidateWildcard(): void
    {
        $validator = new Wildcard();
        $this->assertTrue($validator->isValid([0 => 'string', 1 => 'string']));
        $this->assertTrue($validator->isValid(''));
        $this->assertTrue($validator->isValid([]));
        $this->assertTrue($validator->isValid(1));
        $this->assertTrue($validator->isValid(true));
        $this->assertTrue($validator->isValid(false));
        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $validator->getType());
    }
}
