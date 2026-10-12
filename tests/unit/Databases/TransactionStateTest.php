<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionState;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

final class TransactionStateTest extends TestCase
{
    private const COLLECTION = 'database_1_collection_1';

    private TransactionState $state;

    protected function setUp(): void
    {
        $database = $this->createMock(Database::class);

        $this->state = new TransactionState(
            $database,
            new Authorization(),
            fn () => $database,
            new User()
        );
    }

    /**
     * A row that is already part of the transaction state.
     */
    private function row(): Document
    {
        return new Document([
            '$id' => 'a',
            'grp' => 'g',
            'ix' => 'keep',
            'n' => 1,
            'ds' => 'orig',
            'u' => null,
            'tags' => ['x', 'y'],
            'note' => 'hello world',
        ]);
    }

    /**
     * @param array<Query> $queries
     */
    private function bulkUpdateTouchesRow(array $queries): bool
    {
        $state = [self::COLLECTION => ['a' => $this->row()]];

        $this->state->applyBulkUpdateToState(
            self::COLLECTION,
            new Document(['ds' => 'BULK']),
            $queries,
            $state
        );

        return $state[self::COLLECTION]['a']->getAttribute('ds') === 'BULK';
    }

    /**
     * @param array<Query> $queries
     */
    private function bulkDeleteRemovesRow(array $queries): bool
    {
        $state = [self::COLLECTION => ['a' => $this->row()]];

        $this->state->applyBulkDeleteToState(self::COLLECTION, $queries, $state);

        return !isset($state[self::COLLECTION]['a']);
    }

    /**
     * @return array<string, array{0: array<Query>, 1: bool}>
     */
    public static function filters(): array
    {
        return [
            // Operators that were silently matching every row (the bug)
            'notStartsWith excludes a prefix match' => [[Query::notStartsWith('ix', 'ke')], false],
            'notStartsWith keeps a non match' => [[Query::notStartsWith('ix', 'zz')], true],
            'notEndsWith excludes a suffix match' => [[Query::notEndsWith('ix', 'ep')], false],
            'notEndsWith keeps a non match' => [[Query::notEndsWith('ix', 'zz')], true],
            'notContains excludes an array match' => [[Query::notContains('tags', ['x'])], false],
            'notContains keeps an array non match' => [[Query::notContains('tags', ['z'])], true],
            'notContains excludes a substring match' => [[Query::notContains('note', ['hello'])], false],
            'notContains never matches a null column' => [[Query::notContains('u', ['x'])], false],
            'notBetween excludes values inside the range' => [[Query::notBetween('n', 0, 5)], false],
            'notBetween keeps values outside the range' => [[Query::notBetween('n', 10, 20)], true],
            'or matches when one branch matches' => [[Query::or([Query::equal('ix', ['nope']), Query::equal('ix', ['keep'])])], true],
            'or excludes when no branch matches' => [[Query::or([Query::equal('ix', ['a']), Query::equal('ix', ['b'])])], false],
            'and matches when every branch matches' => [[Query::and([Query::equal('ix', ['keep']), Query::equal('n', [1])])], true],
            'and excludes when one branch fails' => [[Query::and([Query::equal('ix', ['keep']), Query::equal('n', [9])])], false],
            'containsAny matches one of the values' => [[Query::containsAny('tags', ['z', 'x'])], true],
            'containsAny excludes when none match' => [[Query::containsAny('tags', ['z'])], false],
            'containsAll matches when all are present' => [[Query::containsAll('tags', ['x', 'y'])], true],
            'containsAll excludes when one is missing' => [[Query::containsAll('tags', ['x', 'z'])], false],
            'contains matches a substring on strings' => [[Query::contains('note', ['hello'])], true],

            // equal must not use loose comparison
            'equal empty string does not match a null column' => [[Query::equal('u', [''])], false],
            'equal still matches identical strings' => [[Query::equal('ix', ['keep'])], true],
            'equal compares int and float by value' => [[Query::equal('n', [1.0])], true],
            'notEqual still excludes identical strings' => [[Query::notEqual('ix', ['keep'])], false],
            'notEqual keeps different strings' => [[Query::notEqual('ix', ['other'])], true],

            // The issue scenario: equal + notStartsWith together
            'issue scenario: group equal and notStartsWith' => [
                [Query::equal('grp', ['g']), Query::notStartsWith('ix', 'ke')],
                false,
            ],

            // Existing operators must keep working
            'startsWith matches' => [[Query::startsWith('ix', 'ke')], true],
            'endsWith matches' => [[Query::endsWith('ix', 'ep')], true],
            'greaterThan matches' => [[Query::greaterThan('n', 0)], true],
            'lessThan excludes' => [[Query::lessThan('n', 1)], false],
            'between matches' => [[Query::between('n', 0, 5)], true],
            'isNull matches a null column' => [[Query::isNull('u')], true],
            'isNotNull excludes a null column' => [[Query::isNotNull('u')], false],

            // Operators that cannot be evaluated in memory must never match
            'search never matches' => [[Query::search('note', 'hello')], false],
            'regex never matches' => [[Query::regex('ix', '^ke')], false],
        ];
    }

    /**
     * @param array<Query> $queries
     */
    #[DataProvider('filters')]
    public function testBulkUpdateOnlyTouchesMatchingRows(array $queries, bool $shouldMatch): void
    {
        $this->assertSame($shouldMatch, $this->bulkUpdateTouchesRow($queries));
    }

    /**
     * @param array<Query> $queries
     */
    #[DataProvider('filters')]
    public function testBulkDeleteOnlyRemovesMatchingRows(array $queries, bool $shouldMatch): void
    {
        $this->assertSame($shouldMatch, $this->bulkDeleteRemovesRow($queries));
    }

    public function testBulkUpdateKeepsUnmatchedRowUntouched(): void
    {
        $state = [self::COLLECTION => ['a' => $this->row()]];

        $this->state->applyBulkUpdateToState(
            self::COLLECTION,
            new Document(['ds' => 'BULK', 'n' => 101]),
            [Query::equal('grp', ['g']), Query::notStartsWith('ix', 'ke')],
            $state
        );

        $this->assertSame('orig', $state[self::COLLECTION]['a']->getAttribute('ds'));
        $this->assertSame(1, $state[self::COLLECTION]['a']->getAttribute('n'));
    }

    public function testBulkUpdateOnlyChangesTheMatchingRow(): void
    {
        $other = new Document(['$id' => 'b', 'grp' => 'g', 'ix' => 'other', 'n' => 9, 'ds' => 'orig']);
        $state = [self::COLLECTION => ['a' => $this->row(), 'b' => $other]];

        $this->state->applyBulkUpdateToState(
            self::COLLECTION,
            new Document(['ds' => 'BULK']),
            [Query::equal('grp', ['g']), Query::notStartsWith('ix', 'ke')],
            $state
        );

        $this->assertSame('orig', $state[self::COLLECTION]['a']->getAttribute('ds'));
        $this->assertSame('BULK', $state[self::COLLECTION]['b']->getAttribute('ds'));
    }
}