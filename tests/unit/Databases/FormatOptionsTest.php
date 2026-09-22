<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\FormatOptions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class FormatOptionsTest extends TestCase
{
    public function testDecodedFormatOptionsExposeMinAndMax(): void
    {
        $attribute = new Document([
            'key' => 'score',
            'formatOptions' => [
                'min' => 1.5,
                'max' => 10.5,
            ],
        ]);

        FormatOptions::apply($attribute);

        $this->assertEqualsWithDelta(1.5, $attribute->getAttribute('min'), \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(10.5, $attribute->getAttribute('max'), \PHP_FLOAT_EPSILON);
    }

    public function testStringFormatOptionsAreDecoded(): void
    {
        $attribute = new Document([
            'key' => 'score',
            'formatOptions' => '{"min":1.5,"max":10.5}',
        ]);

        FormatOptions::apply($attribute);

        $this->assertEqualsWithDelta(1.5, $attribute->getAttribute('min'), \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(10.5, $attribute->getAttribute('max'), \PHP_FLOAT_EPSILON);
    }
}
