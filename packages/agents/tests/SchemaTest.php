<?php

namespace Utopia\Agents\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Schema;

class SchemaTest extends TestCase
{
    private function weather(): Schema
    {
        return Schema::object([
            'city' => Schema::string('City name'),
            'days' => Schema::integer(),
            'unit' => Schema::enum(['celsius', 'fahrenheit']),
            'tags' => Schema::array(Schema::string()),
            'note' => Schema::nullable(Schema::string()),
        ], description: 'Forecast request');
    }

    public function testObjectRequiresEveryPropertyAndForbidsExtras(): void
    {
        $definition = $this->weather()->toArray();

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['city', 'days', 'unit', 'tags', 'note'], $definition['required']);
        $this->assertFalse($definition['additionalProperties']);
        $this->assertSame('Forecast request', $definition['description']);
        $this->assertSame(['type' => 'string', 'description' => 'City name'], $definition['properties']['city']);
        $this->assertSame(['type' => ['string', 'null']], $definition['properties']['note']);
        $this->assertSame(['type' => 'string', 'enum' => ['celsius', 'fahrenheit']], $definition['properties']['unit']);
    }

    public function testEmptyObjectEncodesPropertiesAsObject(): void
    {
        $this->assertSame('{"type":"object","properties":{},"required":[],"additionalProperties":false}', json_encode(Schema::object([])->toArray()));
    }

    public function testValidAcceptsMatchingValue(): void
    {
        $errors = $this->weather()->validate([
            'city' => 'Berlin',
            'days' => 3,
            'unit' => 'celsius',
            'tags' => ['rain'],
            'note' => null,
        ]);

        $this->assertSame([], $errors);
    }

    public function testValidateReportsEveryViolationWithPath(): void
    {
        $errors = $this->weather()->validate([
            'city' => 42,
            'unit' => 'kelvin',
            'tags' => ['ok', 7],
            'note' => 'fine',
            'extra' => true,
        ]);

        $this->assertSame([
            '$.days: is required',
            '$.city: must be of type string',
            '$.unit: must be one of ["celsius","fahrenheit"]',
            '$.tags[1]: must be of type string',
            '$.extra: is not an allowed property',
        ], $errors);
    }

    public function testValidateChecksBoundsAndLengths(): void
    {
        $schema = Schema::fromArray([
            'type' => 'object',
            'properties' => [
                'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 150],
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4],
                'list' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'number']],
            ],
        ]);

        $this->assertSame([
            '$.age: violates maximum 150',
            '$.name: must be at most 4 characters',
            '$.list: must have at least 1 items',
        ], $schema->validate(['age' => 200, 'name' => 'Alexander', 'list' => []]));
        $this->assertSame([], $schema->validate(['age' => 5.0, 'name' => 'Ana', 'list' => [1, 2.5]]));
    }

    public function testValidateSupportsAnyOf(): void
    {
        $schema = Schema::fromArray(['anyOf' => [
            Schema::object(['id' => Schema::string()])->toArray(),
            ['type' => 'integer'],
        ]]);

        $this->assertSame([], $schema->validate(7));
        $this->assertSame([], $schema->validate(['id' => 'a']));
        $this->assertSame(['$: does not match any allowed schema'], $schema->validate('a'));
    }

    public function testOneOfRequiresExactlyOneMatch(): void
    {
        $schema = Schema::fromArray(['oneOf' => [['type' => 'integer'], ['type' => 'number']]]);

        $this->assertSame([], $schema->validate(1.5));
        $this->assertSame(['$: matches more than one schema'], $schema->validate(2));
        $this->assertSame(['$: does not match any allowed schema'], $schema->validate('a'));
    }

    public function testNullableAcceptsNull(): void
    {
        $schema = Schema::nullable(Schema::object(['id' => Schema::string()]));

        $this->assertSame([], $schema->validate(null));
        $this->assertSame(['$: must be of type object or null'], $schema->validate('a'));
        $this->assertSame(
            ['anyOf' => [['enum' => [1, 2]], ['type' => 'null']]],
            Schema::nullable(Schema::enum([1, 2]))->toArray(),
        );
    }

    public function testDescribeKeepsDefinition(): void
    {
        $schema = Schema::integer()->describe('Count');

        $this->assertSame(['type' => 'integer', 'description' => 'Count'], $schema->toArray());
        $this->assertSame('Count', $schema->description());
    }
}
