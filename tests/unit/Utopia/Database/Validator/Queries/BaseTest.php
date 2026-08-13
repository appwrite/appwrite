<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Functions;
use Appwrite\Utopia\Database\Validator\Queries\Presences;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Database\Validator\Queries\Rules;
use Appwrite\Utopia\Database\Validator\Queries\Variables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;

final class BaseTest extends TestCase
{
    public function testQueryAtMaxValuesIsValid(): void
    {
        $validator = new Presences();
        $values = \array_fill(0, APP_DATABASE_QUERY_MAX_VALUES, 'user');

        $this->assertTrue(
            $validator->isValid([Query::equal('userId', $values)]),
            $validator->getDescription()
        );
    }

    public function testQueryOverMaxValuesIsInvalid(): void
    {
        $validator = new Presences();
        $values = \array_fill(0, APP_DATABASE_QUERY_MAX_VALUES + 1, 'user');

        $this->assertFalse(
            $validator->isValid([Query::equal('userId', $values)]),
            'Query with ' . \count($values) . ' values must exceed the ' . APP_DATABASE_QUERY_MAX_VALUES . ' value cap'
        );
        $this->assertStringContainsString(
            'greater than ' . APP_DATABASE_QUERY_MAX_VALUES . ' values',
            $validator->getDescription(),
            'Rejection must be reported against the configured cap, not the validator default'
        );
    }

    public function testSequenceTypedAttributeIsValidated(): void
    {
        $validator = new Presences();

        $this->assertTrue(
            $validator->isValid([Query::equal('userInternalId', [1])]),
            $validator->getDescription()
        );
        $this->assertFalse(
            $validator->isValid([Query::equal('userInternalId', [1.5])]),
            'Fractional value must not validate against a sequence attribute'
        );
    }

    /**
     * @return \Iterator<string, array{0: Queries, 1: string}>
     */
    public static function searchWithoutFulltextIndexProvider(): \Iterator
    {
        yield 'functions name' => [new Functions(), 'name'];
        yield 'variables key' => [new Variables(), 'key'];
        yield 'rules domain' => [new Rules(), 'domain'];
    }

    /**
     * Only the synthetic 'search' attribute carries a fulltext index, so searching
     * any other attribute must be rejected here rather than reaching the database,
     * where it surfaces as an uncaught QueryException.
     */
    #[DataProvider('searchWithoutFulltextIndexProvider')]
    public function testSearchWithoutFulltextIndexIsInvalid(Queries $validator, string $attribute): void
    {
        $this->assertFalse(
            $validator->isValid([Query::search($attribute, 'value')]),
            "Search on '{$attribute}' must be rejected without a fulltext index on it"
        );
        $this->assertStringContainsString(
            "Searching by attribute \"{$attribute}\" requires a fulltext index",
            $validator->getDescription()
        );
    }

    public function testSearchOnFulltextIndexedAttributeIsValid(): void
    {
        $validator = new Projects();

        $this->assertTrue(
            $validator->isValid([Query::search('search', 'value')]),
            $validator->getDescription()
        );
    }

    public function testNonSearchQueriesAreUnaffected(): void
    {
        $validator = new Functions();

        $this->assertTrue($validator->isValid([Query::equal('name', ['value'])]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::startsWith('name', 'value')]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::orderDesc('name')]), $validator->getDescription());
        $this->assertTrue($validator->isValid([Query::limit(5), Query::offset(2)]), $validator->getDescription());
    }
}
