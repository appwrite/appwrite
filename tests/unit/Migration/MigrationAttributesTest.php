<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../app/init.php';

final class MigrationAttributesTest extends TestCase
{
    public function testAJsonAttributeIsCreatedWithItsDefaultEncoded(): void
    {
        $created = $this->createFrom('projects', 'services');

        $this->assertSame('services', $created->key);
        $this->assertSame('[]', $created->default);
    }

    public function testAnAttributeWithoutTheJsonFilterKeepsItsDefault(): void
    {
        $created = $this->createFrom('projects', 'name');

        $this->assertSame('name', $created->key);
        $this->assertNull($created->default);
    }

    public function testAttributesFromCollectionSkipTheExistingOnesAndEncodeJsonDefaults(): void
    {
        $created = [];

        $database = $this->createStub(Database::class);
        $database->method('findCollection')->willReturn(Collection::create(id: 'projects', attributes: [
            Attribute::string(key: 'name', size: 128),
        ]));
        $database->method('createAttributes')->willReturnCallback(
            static function (string $collection, array $attributes) use (&$created): array {
                $created = $attributes;

                return $attributes;
            }
        );

        \ob_start();
        try {
            $this->migration()->createAttributesFromCollection($database, 'projects', ['name', 'services', 'apis']);
        } finally {
            \ob_end_clean();
        }

        $this->assertSame(
            [['services', '[]'], ['apis', '[]']],
            \array_map(static fn (Attribute $attribute): array => [$attribute->key, $attribute->default], $created),
        );
    }

    private function createFrom(string $collectionId, string $attributeId): Attribute
    {
        $created = null;

        $database = $this->createStub(Database::class);
        $database->method('createAttribute')->willReturnCallback(
            static function (string $collection, Attribute $attribute) use (&$created): Attribute {
                $created = $attribute;

                return $attribute;
            }
        );

        $this->migration()->createAttributeFromCollection($database, $collectionId, $attributeId);

        $this->assertInstanceOf(Attribute::class, $created);

        return $created;
    }

    private function migration(): Migration
    {
        return new class () extends Migration {
            public function __construct()
            {
                parent::__construct();
                $this->project = new Document(['$id' => 'console', '$sequence' => 'console']);
            }

            #[\Override]
            public function execute(): void
            {
            }
        };
    }
}
