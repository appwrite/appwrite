<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Validator;

use Appwrite\Utopia\Validator\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testRejectsWhitespaceOnly(): void
    {
        $validator = new Text(128);

        $this->assertFalse($validator->isValid(' '));
        $this->assertFalse($validator->isValid('   '));
        $this->assertFalse($validator->isValid("\t"));
        $this->assertFalse($validator->isValid("\n"));
    }

    public function testRejectsEmptyWhenMinIsOne(): void
    {
        $validator = new Text(128);

        $this->assertFalse($validator->isValid(''));
    }

    public function testAllowsEmptyWhenMinIsZero(): void
    {
        $validator = new Text(128, 0);

        $this->assertTrue($validator->isValid(''));
        $this->assertFalse($validator->isValid(' '));
    }

    public function testAcceptsFilledText(): void
    {
        $validator = new Text(128);

        $this->assertTrue($validator->isValid('My Android app'));
        $this->assertTrue($validator->isValid('com.example.app'));
        $this->assertTrue($validator->isValid('localhost'));
    }

    public function testStillEnforcesMaxLength(): void
    {
        $validator = new Text(3);

        $this->assertTrue($validator->isValid('abc'));
        $this->assertFalse($validator->isValid('abcd'));
    }
}
