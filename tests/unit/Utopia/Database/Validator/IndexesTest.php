<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\Indexes;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;

final class IndexesTest extends TestCase
{
    protected Indexes $object;

    public function setUp(): void
    {
        $this->object = new Indexes();
    }

    public function testDescription(): void
    {
        $this->assertTrue($this->object->isValid([
            ['key' => 'titleIndex', 'type' => Database::INDEX_KEY, 'attributes' => ['title'], 'description' => 'Speeds up lookups by title'],
            ['key' => 'bodyIndex', 'type' => Database::INDEX_FULLTEXT, 'attributes' => ['body'], 'description' => \str_repeat('a', 1024)],
            ['key' => 'slugIndex', 'type' => Database::INDEX_UNIQUE, 'attributes' => ['slug']],
        ]), $this->object->getDescription());

        $this->assertFalse($this->object->isValid([
            ['key' => 'titleIndex', 'type' => Database::INDEX_KEY, 'attributes' => ['title'], 'description' => 42],
        ]));

        $this->assertFalse($this->object->isValid([
            ['key' => 'titleIndex', 'type' => Database::INDEX_KEY, 'attributes' => ['title'], 'description' => \str_repeat('a', 1025)],
        ]));
    }
}
