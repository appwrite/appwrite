<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testCanValidateText(): void
    {
        $validator = new Text(10);
        $this->assertTrue($validator->isValid('text'));
        $this->assertTrue($validator->isValid('7'));
        $this->assertTrue($validator->isValid('7.9'));
        $this->assertTrue($validator->isValid('["seven"]'));
        $this->assertFalse($validator->isValid(['seven']));
        $this->assertFalse($validator->isValid(['seven', 8, 9.0]));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $validator->getType());
    }

    public function testCanValidateBoundaries(): void
    {
        $validator = new Text(5);
        $this->assertTrue($validator->isValid('hell'));
        $this->assertTrue($validator->isValid('hello'));
        $this->assertFalse($validator->isValid('hellow'));
        $this->assertFalse($validator->isValid(''));

        $validator = new Text(5, 3);
        $this->assertTrue($validator->isValid('hel'));
        $this->assertTrue($validator->isValid('hell'));
        $this->assertTrue($validator->isValid('hello'));
        $this->assertFalse($validator->isValid('hellow'));
        $this->assertFalse($validator->isValid('he'));
        $this->assertFalse($validator->isValid('h'));
    }

    public function testCanRequireNonBlankText(): void
    {
        $validator = new Text(20, min: 0);
        $this->assertTrue($validator->isValid(''));
        $this->assertTrue($validator->isValid(" \t\n"));
        $this->assertTrue($validator->isValid("\u{00A0}"));
        $this->assertTrue($validator->isValid("\u{200D}"));

        $validator = new Text(20, min: 0, requireNonBlank: true);
        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(" \t\n"));
        $this->assertFalse($validator->isValid("\u{00A0}"));
        $this->assertFalse($validator->isValid("\u{200D}"));
        $this->assertTrue($validator->isValid('My App'));
        $this->assertTrue($validator->isValid('  My App  '));
        $this->assertSame(
            'Value must be a valid string and no longer than 20 chars and not be blank',
            $validator->getDescription(),
        );
    }

    public function testCanValidateTextWithAllowList(): void
    {
        // Test lowercase alphabet
        $validator = new Text(100, allowList: Text::ALPHABET_LOWER);
        $this->assertFalse($validator->isArray());
        $this->assertTrue($validator->isValid('qwertzuiopasdfghjklyxcvbnm'));
        $this->assertTrue($validator->isValid('hello'));
        $this->assertTrue($validator->isValid('world'));
        $this->assertFalse($validator->isValid('hello world'));
        $this->assertFalse($validator->isValid('Hello'));
        $this->assertFalse($validator->isValid('worlD'));
        $this->assertFalse($validator->isValid('hello123'));

        // Test uppercase alphabet
        $validator = new Text(100, allowList: Text::ALPHABET_UPPER);
        $this->assertFalse($validator->isArray());
        $this->assertTrue($validator->isValid('QWERTZUIOPASDFGHJKLYXCVBNM'));
        $this->assertTrue($validator->isValid('HELLO'));
        $this->assertTrue($validator->isValid('WORLD'));
        $this->assertFalse($validator->isValid('HELLO WORLD'));
        $this->assertFalse($validator->isValid('hELLO'));
        $this->assertFalse($validator->isValid('WORLd'));
        $this->assertFalse($validator->isValid('HELLO123'));

        // Test numbers
        $validator = new Text(100, allowList: Text::NUMBERS);
        $this->assertFalse($validator->isArray());
        $this->assertTrue($validator->isValid('1234567890'));
        $this->assertTrue($validator->isValid('123'));
        $this->assertFalse($validator->isValid('123 456'));
        $this->assertFalse($validator->isValid('hello123'));

        // Test combination of allowLists
        $validator = new Text(100, allowList: [
            ...Text::ALPHABET_LOWER,
            ...Text::ALPHABET_UPPER,
            ...Text::NUMBERS,
        ]);

        $this->assertFalse($validator->isArray());
        $this->assertTrue($validator->isValid('1234567890'));
        $this->assertTrue($validator->isValid('qwertzuiopasdfghjklyxcvbnm'));
        $this->assertTrue($validator->isValid('QWERTZUIOPASDFGHJKLYXCVBNM'));
        $this->assertTrue($validator->isValid('QWERTZUIOPASDFGHJKLYXCVBNMqwertzuiopasdfghjklyxcvbnm1234567890'));
        $this->assertFalse($validator->isValid('hello-world'));
        $this->assertFalse($validator->isValid('hello_world'));
        $this->assertFalse($validator->isValid('hello/world'));
    }
}
