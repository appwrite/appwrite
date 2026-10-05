<?php

namespace Tests\Utopia\Agents;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;

class SchemaTest extends TestCase
{
    private Schema $schema;

    private SchemaObject $object;

    private string $name = 'TestSchema';

    private string $description = 'A test schema.';

    /**
     * @var array<int, string>
     */
    private array $required = ['id', 'name'];

    protected function setUp(): void
    {
        $this->object = new SchemaObject([
            'id' => [
                'type' => SchemaObject::TYPE_STRING,
                'description' => 'The ID of the user',
            ],
            'name' => [
                'type' => SchemaObject::TYPE_STRING,
                'description' => 'The name of the user',
            ],
            'age' => [
                'type' => SchemaObject::TYPE_INTEGER,
                'description' => 'The age of the user',
            ],
        ]);
        $this->schema = new Schema(
            $this->name,
            $this->description,
            $this->object,
            $this->required
        );
    }

    public function testConstructorAndGetters(): void
    {
        $this->assertSame($this->name, $this->schema->getName());
        $this->assertSame($this->description, $this->schema->getDescription());
        $this->assertSame($this->object, $this->schema->getObject());
        $this->assertSame($this->required, $this->schema->getRequired());
    }

    public function testToJson(): void
    {
        $json = $this->schema->toJson();

        $jsonArray = json_decode($json, true);
        $this->assertIsArray($jsonArray);
        $this->assertArrayHasKey('id', $jsonArray);
        $this->assertArrayHasKey('name', $jsonArray);
        $this->assertArrayHasKey('age', $jsonArray);
        $this->assertSame('The ID of the user (string)', $jsonArray['id']);
        $this->assertSame('The name of the user (string)', $jsonArray['name']);
        $this->assertSame('The age of the user (integer)', $jsonArray['age']);
    }
}
