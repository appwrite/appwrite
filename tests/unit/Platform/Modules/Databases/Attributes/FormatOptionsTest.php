<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Attributes;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
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

        Action::applyFormatOptions($attribute);

        $this->assertSame(1.5, $attribute->getAttribute('min'));
        $this->assertSame(10.5, $attribute->getAttribute('max'));
    }

    public function testStringFormatOptionsAreDecoded(): void
    {
        $attribute = new Document([
            'key' => 'score',
            'formatOptions' => '{"min":1.5,"max":10.5}',
        ]);

        Action::applyFormatOptions($attribute);

        $this->assertEquals(1.5, $attribute->getAttribute('min'));
        $this->assertEquals(10.5, $attribute->getAttribute('max'));
    }
}
