<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Validator;

use Appwrite\Utopia\Request\Validator\NonBlank;
use PHPUnit\Framework\TestCase;
use Utopia\Validator\Text;

final class NonBlankTest extends TestCase
{
    public function testRejectsWhitespaceOnly(): void
    {
        $validator = new NonBlank(new Text(128));

        $this->assertFalse($validator->isValid(' '));
        $this->assertFalse($validator->isValid("\t"));
        $this->assertFalse($validator->isValid(" \n "));
    }

    public function testAcceptsEmptyAndNormalText(): void
    {
        $validator = new NonBlank(new Text(128, min: 0));

        $this->assertTrue($validator->isValid(''));
        $this->assertTrue($validator->isValid('Appwrite'));
        $this->assertTrue($validator->isValid(' spaced name '));
    }
}
