<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Validator\HexColor;

final class HexColorTest extends TestCase
{
    public function testCanValidateHexColor(): void
    {
        $hexColor = new HexColor();
        $this->assertTrue($hexColor->isValid('000'));
        $this->assertTrue($hexColor->isValid('ffffff'));
        $this->assertTrue($hexColor->isValid('fff'));
        $this->assertTrue($hexColor->isValid('000000'));

        $this->assertFalse($hexColor->isValid('AB10BC99'));
        $this->assertFalse($hexColor->isValid('AR1012'));
        $this->assertFalse($hexColor->isValid('ab12bc99'));
        $this->assertFalse($hexColor->isValid('00'));
        $this->assertFalse($hexColor->isValid('ffff'));
        $this->assertFalse($hexColor->isArray());

        $this->assertSame(\Utopia\Validator::TYPE_STRING, $hexColor->getType());
    }
}
