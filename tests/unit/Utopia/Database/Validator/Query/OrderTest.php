<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\RelationshipSide;
use Utopia\Database\RelationshipType;
use Utopia\Query\Schema\ColumnType;

final class OrderTest extends TestCase
{
    private static function relationship(string $key, RelationshipType $type, RelationshipSide $side, bool $twoWay = true): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::Relationship->value,
            'status' => 'available',
            'options' => [
                'relatedCollection' => 'related',
                'relationType' => $type->value,
                'twoWay' => $twoWay,
                'twoWayKey' => 'inverse',
                'onDelete' => 'restrict',
                'side' => $side->value,
            ],
        ]);
    }

    /**
     * @return array<Document>
     */
    private static function attributes(): array
    {
        return [
            new Document([
                '$id' => 'title',
                'key' => 'title',
                'type' => ColumnType::String->value,
                'size' => 128,
                'status' => 'available',
            ]),
            self::relationship('oneToManyParent', RelationshipType::OneToMany, RelationshipSide::Parent),
            self::relationship('oneToManyChild', RelationshipType::OneToMany, RelationshipSide::Child),
            self::relationship('manyToOneParent', RelationshipType::ManyToOne, RelationshipSide::Parent),
            self::relationship('manyToOneChild', RelationshipType::ManyToOne, RelationshipSide::Child),
            self::relationship('manyToManyParent', RelationshipType::ManyToMany, RelationshipSide::Parent),
            self::relationship('oneToOneParent', RelationshipType::OneToOne, RelationshipSide::Parent, twoWay: false),
            self::relationship('oneToOneChild', RelationshipType::OneToOne, RelationshipSide::Child),
        ];
    }

    public static function columnlessProvider(): \Iterator
    {
        yield 'one to many parent' => ['oneToManyParent'];
        yield 'many to one child' => ['manyToOneChild'];
        yield 'many to many' => ['manyToManyParent'];
    }

    #[DataProvider('columnlessProvider')]
    public function testRejectsRelationshipWithoutColumn(string $attribute): void
    {
        $validator = new Order(self::attributes());

        $this->assertFalse($validator->isValid(Query::orderAsc($attribute)));
        $this->assertSame('Invalid query: Cannot order by relationship attribute: ' . $attribute, $validator->getDescription());
        $this->assertFalse($validator->isValid(Query::orderDesc($attribute)));
    }

    public static function columnProvider(): \Iterator
    {
        yield 'plain attribute' => ['title'];
        yield 'one to many child' => ['oneToManyChild'];
        yield 'many to one parent' => ['manyToOneParent'];
        yield 'one to one parent' => ['oneToOneParent'];
        yield 'one to one child' => ['oneToOneChild'];
        yield 'internal attribute' => ['$createdAt'];
        yield 'unknown attribute' => ['missing'];
    }

    #[DataProvider('columnProvider')]
    public function testAcceptsAttributeWithColumn(string $attribute): void
    {
        $validator = new Order(self::attributes());

        $this->assertTrue($validator->isValid(Query::orderAsc($attribute)), $validator->getDescription());
        $this->assertTrue($validator->isValid(Query::orderDesc($attribute)), $validator->getDescription());
    }

    public function testRejectsNonOrderQuery(): void
    {
        $validator = new Order(self::attributes());

        $this->assertFalse($validator->isValid(Query::limit(1)));
        $this->assertSame('Invalid query method: limit', $validator->getDescription());
    }
}
