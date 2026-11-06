<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database;

use Appwrite\Utopia\Database\Attribute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Query\Schema\ColumnType;

final class AttributeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function doubles(): \Iterator
    {
        yield 'without a size' => [['key' => 'score', 'type' => ColumnType::Double->value]];
        yield 'with a size' => [['key' => 'score', 'type' => ColumnType::Double->value, 'size' => 5]];
        yield 'with a negative size' => [['key' => 'score', 'type' => ColumnType::Double->value, 'size' => -1]];
    }

    /**
     * createFloatColumn stores a double with size 0 and takes no size, so an
     * inline double has to resolve to the same whatever size it was sent with.
     *
     * @param array<string, mixed> $definition
     */
    #[DataProvider('doubles')]
    public function testDoubleResolvesToTheSizeOfTheDedicatedEndpoint(array $definition): void
    {
        $this->assertSame(
            ['type' => ColumnType::Double->value, 'format' => '', 'size' => 0],
            Attribute::resolve($definition)
        );
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function bigintSpellings(): \Iterator
    {
        yield 'biginteger' => ['biginteger'];
        yield 'bigint' => ['bigint'];
    }

    #[DataProvider('bigintSpellings')]
    public function testBigIntIsStoredAsBigintWhicheverSpellingItIsSentIn(string $spelling): void
    {
        $this->assertSame('bigint', Attribute::storedType($spelling));
        $this->assertSame('bigint', Attribute::resolve(['key' => 'pages', 'type' => $spelling])['type']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function otherTypes(): \Iterator
    {
        yield 'integer' => [ColumnType::Integer->value];
        yield 'string' => [ColumnType::String->value];
        yield 'double' => [ColumnType::Double->value];
        yield 'relationship' => [ColumnType::Relationship->value];
        yield 'unrecognised' => ['unknown'];
    }

    #[DataProvider('otherTypes')]
    public function testOtherTypesAreStoredAsSent(string $type): void
    {
        $this->assertSame($type, Attribute::storedType($type));
    }

    /**
     * @return \Iterator<string, array{string, string, bool}>
     */
    public static function storedTypes(): \Iterator
    {
        yield 'stored bigint, updated as bigint' => ['bigint', 'bigint', true];
        yield 'stored biginteger, updated as bigint' => ['biginteger', 'bigint', true];
        yield 'stored bigint, updated as biginteger' => ['bigint', 'biginteger', true];
        yield 'stored biginteger, updated as biginteger' => ['biginteger', 'biginteger', true];
        yield 'stored string, updated as string' => [ColumnType::String->value, ColumnType::String->value, true];
        yield 'stored integer, updated as bigint' => [ColumnType::Integer->value, 'bigint', false];
        yield 'stored unrecognised, updated as bigint' => ['unknown', 'bigint', false];
        yield 'stored unrecognised, updated as itself' => ['unknown', 'unknown', false];
        yield 'stored empty, updated as bigint' => ['', 'bigint', false];
    }

    #[DataProvider('storedTypes')]
    public function testAStoredTypeMatchesOnlyItsOwnTypeWhicheverSpellingEitherSideCarries(string $stored, string $type, bool $expected): void
    {
        $this->assertSame($expected, Attribute::sameType($stored, $type));
    }

    /**
     * @return \Iterator<string, array{ColumnType|string, ?ColumnType}>
     */
    public static function columnTypes(): \Iterator
    {
        yield 'stored bigint' => ['bigint', ColumnType::BigInteger];
        yield 'biginteger' => ['biginteger', ColumnType::BigInteger];
        yield 'string' => [ColumnType::String->value, ColumnType::String];
        yield 'relationship' => [ColumnType::Relationship->value, ColumnType::Relationship];
        yield 'a column type' => [ColumnType::Double, ColumnType::Double];
        yield 'unrecognised' => ['unknown', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('columnTypes')]
    public function testColumnTypeResolvesEitherSpellingAndRejectsAnUnrecognisedType(ColumnType|string $type, ?ColumnType $expected): void
    {
        $this->assertSame($expected, Attribute::columnType($type));
    }

    public function testRelationshipsAreTheRelationshipAttributesOfTheCollectionByKey(): void
    {
        $collection = new Document(['attributes' => [
            ['$id' => '1_1_title', 'key' => 'title', 'type' => ColumnType::String->value],
            ['$id' => '1_1_artist', 'key' => 'artist', 'type' => ColumnType::Relationship->value],
            ['key' => 'label', 'type' => ColumnType::Relationship->value],
            ['$id' => '1_1_tracks', 'key' => 'tracks', 'type' => ColumnType::Relationship->value],
        ]]);
        $attributes = $collection->getAttribute('attributes');

        $relationships = Attribute::relationships($collection);

        $this->assertSame(['artist', 'tracks'], \array_keys($relationships), 'relationship attribute documents only, by key; a string attribute and an entry that is not a document are left out');
        $this->assertSame($attributes[1], $relationships['artist']);
        $this->assertSame($attributes[3], $relationships['tracks']);
    }
}
