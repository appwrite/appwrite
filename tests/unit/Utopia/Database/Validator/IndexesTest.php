<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\Indexes;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;

final class IndexesTest extends TestCase
{
    public function testAcceptsTrigramIndex(): void
    {
        $validator = new Indexes();

        $this->assertTrue($validator->isValid([[
            'key' => 'nameTrigram',
            'type' => Database::INDEX_TRIGRAM,
            'attributes' => ['name'],
        ]]));
    }

    public function testRejectsUnknownIndexType(): void
    {
        $validator = new Indexes();

        $this->assertFalse($validator->isValid([[
            'key' => 'name',
            'type' => 'gist',
            'attributes' => ['name'],
        ]]));
        $this->assertSame("Invalid type for index 'name': gist", $validator->getDescription());
    }
}
