<?php

namespace Tests\Unit\Validator\Query;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Base;
use Utopia\Database\Validator\Query\Select;

class SelectTest extends TestCase
{
    protected Base|null $validator = null;

    /**
     * @throws Exception
     */
    public function setUp(): void
    {
        $this->validator = new Select(
            attributes: [
                new Document([
                    '$id' => 'attr',
                    'key' => 'attr',
                    'type' => Database::VAR_STRING,
                    'array' => false,
                ]),
                new Document([
                    '$id' => 'artist',
                    'key' => 'artist',
                    'type' => Database::VAR_RELATIONSHIP,
                    'array' => false,
                ]),
            ],
        );
    }

    public function testValueSuccess(): void
    {
        $this->assertTrue($this->validator->isValid(Query::select(['*', 'attr'])));
        $this->assertTrue($this->validator->isValid(Query::select(['artist.name'])));
    }

    public function testValueFailure(): void
    {
        $this->assertFalse($this->validator->isValid(Query::limit(1)));
        $this->assertEquals('Invalid query', $this->validator->getDescription());
        $this->assertFalse($this->validator->isValid(Query::select(['name.artist'])));
    }

    /**
     * A non-string selection used to reach str_contains() and raise a TypeError,
     * which is an Error rather than an Exception, so it escaped every catch on the
     * way out and surfaced as a 500. Select was the only query method that answered
     * a malformed value that way.
     *
     * @param array<mixed> $values
     *
     * @dataProvider nonStringSelections
     */
    public function testANonStringSelectionIsRefusedByType(array $values, string $expected): void
    {
        $this->assertFalse($this->validator->isValid(Query::select($values)));
        $this->assertSame($expected, $this->validator->getDescription());
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function nonStringSelections(): array
    {
        return [
            'nested array' => [[['attr']], 'Attribute selection must be a string, got array'],
            'nested wildcard' => [[['*']], 'Attribute selection must be a string, got array'],
            'mixed flat and nested' => [['attr', ['x']], 'Attribute selection must be a string, got array'],
            'assoc array' => [[['a' => 1]], 'Attribute selection must be a string, got array'],
            'integer' => [[1], 'Attribute selection must be a string, got int'],
            'null' => [[null], 'Attribute selection must be a string, got null'],
        ];
    }

    /**
     * Two nested values used to collapse to one under array_unique(), which casts
     * every array to the string "Array", so the duplicate check tripped first and
     * reported a duplicate that was not there. The type check has to run before it.
     *
     * Parsed from JSON rather than built with Query::select(), because that is the
     * path a hand-written HTTP client takes and the only one that can carry a value
     * the constructor's array<string> type would reject.
     */
    public function testTwoNestedSelectionsReportTheTypeNotAFalseDuplicate(): void
    {
        $query = Query::parse('{"method":"select","values":[["a"],["b"]]}');

        $this->assertFalse($this->validator->isValid($query));
        $this->assertSame('Attribute selection must be a string, got array', $this->validator->getDescription());
    }

    public function testTheLegitimateFlatFormStillPasses(): void
    {
        $this->assertTrue($this->validator->isValid(Query::select(['attr'])));
        $this->assertTrue($this->validator->isValid(Query::select(['*'])));
        $this->assertTrue($this->validator->isValid(Query::select(['$id', '$createdAt'])));
        $this->assertTrue($this->validator->isValid(Query::select(['artist.name'])));
    }
}
