<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Validator;

use Appwrite\Utopia\Request\Validator\NonBlank;
use PHPUnit\Framework\TestCase;
use Utopia\Validator\Hostname;
use Utopia\Validator\Text;

final class NonBlankTest extends TestCase
{
    public function testRejectsWhitespaceOnlyText(): void
    {
        $validator = new NonBlank(new Text(128));

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(' '));
        $this->assertFalse($validator->isValid("\t"));
        $this->assertFalse($validator->isValid(" \n "));
        $this->assertFalse($validator->isValid("\u{00A0}")); // NBSP
        $this->assertFalse($validator->isValid("\u{3000}")); // ideographic space
        $this->assertFalse($validator->isValid("\u{00A0}\u{3000}"));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid([]));

        $this->assertTrue($validator->isValid('My App'));
        $this->assertTrue($validator->isValid(' a '));
        $this->assertTrue($validator->isValid("a\u{00A0}b"));
    }

    public function testRejectsWhitespaceOnlyHostname(): void
    {
        $validator = new NonBlank(new Hostname());

        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(' '));
        $this->assertTrue($validator->isValid('app.example.com'));
    }

    public function testDelegatesDescriptionAndType(): void
    {
        $inner = new Text(128);
        $validator = new NonBlank($inner);

        $this->assertSame($inner->getType(), $validator->getType());
        $this->assertSame($inner->isArray(), $validator->isArray());
        $this->assertStringContainsString('must not be blank', $validator->getDescription());
        $this->assertStringContainsString($inner->getDescription(), $validator->getDescription());
    }
}
