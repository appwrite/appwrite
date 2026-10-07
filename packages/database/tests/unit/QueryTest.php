<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;

class QueryTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testCreate(): void
    {
        $query = new Query(Query::TYPE_EQUAL, 'title', ['Iron Man']);

        $this->assertEquals(Query::TYPE_EQUAL, $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals('Iron Man', $query->getValues()[0]);

        $query = new Query(Query::TYPE_ORDER_DESC, 'score');

        $this->assertEquals(Query::TYPE_ORDER_DESC, $query->getMethod());
        $this->assertEquals('score', $query->getAttribute());
        $this->assertEquals([], $query->getValues());

        $query = new Query(Query::TYPE_LIMIT, values: [10]);

        $this->assertEquals(Query::TYPE_LIMIT, $query->getMethod());
        $this->assertEquals('', $query->getAttribute());
        $this->assertEquals(10, $query->getValues()[0]);

        $query = Query::equal('title', ['Iron Man']);

        $this->assertEquals(Query::TYPE_EQUAL, $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals('Iron Man', $query->getValues()[0]);

        $query = Query::greaterThan('score', 10);

        $this->assertEquals(Query::TYPE_GREATER, $query->getMethod());
        $this->assertEquals('score', $query->getAttribute());
        $this->assertEquals(10, $query->getValues()[0]);

        // Test vector queries
        $vector = [0.1, 0.2, 0.3];

        $query = Query::vectorDot('embedding', $vector);
        $this->assertEquals(Query::TYPE_VECTOR_DOT, $query->getMethod());
        $this->assertEquals('embedding', $query->getAttribute());
        $this->assertEquals([$vector], $query->getValues());

        $query = Query::vectorCosine('embedding', $vector);
        $this->assertEquals(Query::TYPE_VECTOR_COSINE, $query->getMethod());
        $this->assertEquals('embedding', $query->getAttribute());
        $this->assertEquals([$vector], $query->getValues());

        $query = Query::vectorEuclidean('embedding', $vector);
        $this->assertEquals(Query::TYPE_VECTOR_EUCLIDEAN, $query->getMethod());
        $this->assertEquals('embedding', $query->getAttribute());
        $this->assertEquals([$vector], $query->getValues());

        $query = Query::search('search', 'John Doe');

        $this->assertEquals(Query::TYPE_SEARCH, $query->getMethod());
        $this->assertEquals('search', $query->getAttribute());
        $this->assertEquals('John Doe', $query->getValues()[0]);

        $query = Query::orderAsc('score');

        $this->assertEquals(Query::TYPE_ORDER_ASC, $query->getMethod());
        $this->assertEquals('score', $query->getAttribute());
        $this->assertEquals([], $query->getValues());

        $query = Query::limit(10);

        $this->assertEquals(Query::TYPE_LIMIT, $query->getMethod());
        $this->assertEquals('', $query->getAttribute());
        $this->assertEquals([10], $query->getValues());

        $cursor = new Document();
        $query = Query::cursorAfter($cursor);

        $this->assertEquals(Query::TYPE_CURSOR_AFTER, $query->getMethod());
        $this->assertEquals('', $query->getAttribute());
        $this->assertEquals([$cursor], $query->getValues());

        $query = Query::isNull('title');

        $this->assertEquals(Query::TYPE_IS_NULL, $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals([], $query->getValues());

        $query = Query::isNotNull('title');

        $this->assertEquals(Query::TYPE_IS_NOT_NULL, $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals([], $query->getValues());

        $query = Query::notContains('tags', ['test', 'example']);

        $this->assertEquals(Query::TYPE_NOT_CONTAINS, $query->getMethod());
        $this->assertEquals('tags', $query->getAttribute());
        $this->assertEquals(['test', 'example'], $query->getValues());

        $query = Query::notSearch('content', 'keyword');

        $this->assertEquals(Query::TYPE_NOT_SEARCH, $query->getMethod());
        $this->assertEquals('content', $query->getAttribute());
        $this->assertEquals(['keyword'], $query->getValues());

        $query = Query::notStartsWith('title', 'prefix');

        $this->assertEquals(Query::TYPE_NOT_STARTS_WITH, $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals(['prefix'], $query->getValues());

        $query = Query::notEndsWith('url', '.html');

        $this->assertEquals(Query::TYPE_NOT_ENDS_WITH, $query->getMethod());
        $this->assertEquals('url', $query->getAttribute());
        $this->assertEquals(['.html'], $query->getValues());

        $query = Query::notBetween('score', 10, 20);

        $this->assertEquals(Query::TYPE_NOT_BETWEEN, $query->getMethod());
        $this->assertEquals('score', $query->getAttribute());
        $this->assertEquals([10, 20], $query->getValues());

        // Test new date query wrapper methods
        $query = Query::createdBefore('2023-01-01T00:00:00.000Z');

        $this->assertEquals(Query::TYPE_LESSER, $query->getMethod());
        $this->assertEquals('$createdAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z'], $query->getValues());

        $query = Query::createdAfter('2023-01-01T00:00:00.000Z');

        $this->assertEquals(Query::TYPE_GREATER, $query->getMethod());
        $this->assertEquals('$createdAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z'], $query->getValues());

        $query = Query::updatedBefore('2023-12-31T23:59:59.999Z');

        $this->assertEquals(Query::TYPE_LESSER, $query->getMethod());
        $this->assertEquals('$updatedAt', $query->getAttribute());
        $this->assertEquals(['2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::updatedAfter('2023-12-31T23:59:59.999Z');

        $this->assertEquals(Query::TYPE_GREATER, $query->getMethod());
        $this->assertEquals('$updatedAt', $query->getAttribute());
        $this->assertEquals(['2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::createdBetween('2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z');

        $this->assertEquals(Query::TYPE_BETWEEN, $query->getMethod());
        $this->assertEquals('$createdAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::updatedBetween('2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z');

        $this->assertEquals(Query::TYPE_BETWEEN, $query->getMethod());
        $this->assertEquals('$updatedAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z'], $query->getValues());

        // Test orderRandom query
        $query = Query::orderRandom();
        $this->assertEquals(Query::TYPE_ORDER_RANDOM, $query->getMethod());
        $this->assertEquals('', $query->getAttribute());
        $this->assertEquals([], $query->getValues());
    }

    /**
     * @return void
     * @throws QueryException
     */
    public function testParse(): void
    {
        $jsonString = Query::equal('title', ['Iron Man'])->toString();
        $query = Query::parse($jsonString);
        $this->assertEquals('{"method":"equal","attribute":"title","values":["Iron Man"]}', $jsonString);
        $this->assertEquals('equal', $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals('Iron Man', $query->getValues()[0]);

        $query = Query::parse(Query::lessThan('year', 2001)->toString());
        $this->assertEquals('lessThan', $query->getMethod());
        $this->assertEquals('year', $query->getAttribute());
        $this->assertEquals(2001, $query->getValues()[0]);

        $query = Query::parse(Query::equal('published', [true])->toString());
        $this->assertEquals('equal', $query->getMethod());
        $this->assertEquals('published', $query->getAttribute());
        $this->assertTrue($query->getValues()[0]);

        $query = Query::parse(Query::equal('published', [false])->toString());
        $this->assertEquals('equal', $query->getMethod());
        $this->assertEquals('published', $query->getAttribute());
        $this->assertFalse($query->getValues()[0]);

        $query = Query::parse(Query::equal('actors', [' Johnny Depp ', ' Brad Pitt', 'Al Pacino '])->toString());
        $this->assertEquals('equal', $query->getMethod());
        $this->assertEquals('actors', $query->getAttribute());
        $this->assertEquals(' Johnny Depp ', $query->getValues()[0]);
        $this->assertEquals(' Brad Pitt', $query->getValues()[1]);
        $this->assertEquals('Al Pacino ', $query->getValues()[2]);

        $query = Query::parse(Query::equal('actors', ['Brad Pitt', 'Johnny Depp'])->toString());
        $this->assertEquals('equal', $query->getMethod());
        $this->assertEquals('actors', $query->getAttribute());
        $this->assertEquals('Brad Pitt', $query->getValues()[0]);
        $this->assertEquals('Johnny Depp', $query->getValues()[1]);

        $query = Query::parse(Query::contains('writers', ['Tim O\'Reilly'])->toString());
        $this->assertEquals('contains', $query->getMethod());
        $this->assertEquals('writers', $query->getAttribute());
        $this->assertEquals('Tim O\'Reilly', $query->getValues()[0]);

        $query = Query::parse(Query::greaterThan('score', 8.5)->toString());
        $this->assertEquals('greaterThan', $query->getMethod());
        $this->assertEquals('score', $query->getAttribute());
        $this->assertEquals(8.5, $query->getValues()[0]);

        $query = Query::parse(Query::notContains('tags', ['unwanted', 'spam'])->toString());
        $this->assertEquals('notContains', $query->getMethod());
        $this->assertEquals('tags', $query->getAttribute());
        $this->assertEquals(['unwanted', 'spam'], $query->getValues());

        $query = Query::parse(Query::notSearch('content', 'unwanted content')->toString());
        $this->assertEquals('notSearch', $query->getMethod());
        $this->assertEquals('content', $query->getAttribute());
        $this->assertEquals(['unwanted content'], $query->getValues());

        $query = Query::parse(Query::notStartsWith('title', 'temp')->toString());
        $this->assertEquals('notStartsWith', $query->getMethod());
        $this->assertEquals('title', $query->getAttribute());
        $this->assertEquals(['temp'], $query->getValues());

        $query = Query::parse(Query::notEndsWith('filename', '.tmp')->toString());
        $this->assertEquals('notEndsWith', $query->getMethod());
        $this->assertEquals('filename', $query->getAttribute());
        $this->assertEquals(['.tmp'], $query->getValues());

        $query = Query::parse(Query::notBetween('score', 0, 50)->toString());
        $this->assertEquals('notBetween', $query->getMethod());
        $this->assertEquals('score', $query->getAttribute());
        $this->assertEquals([0, 50], $query->getValues());

        $query = Query::parse(Query::notEqual('director', 'null')->toString());
        $this->assertEquals('notEqual', $query->getMethod());
        $this->assertEquals('director', $query->getAttribute());
        $this->assertEquals('null', $query->getValues()[0]);

        $query = Query::parse(Query::isNull('director')->toString());
        $this->assertEquals('isNull', $query->getMethod());
        $this->assertEquals('director', $query->getAttribute());
        $this->assertEquals([], $query->getValues());

        $query = Query::parse(Query::isNotNull('director')->toString());
        $this->assertEquals('isNotNull', $query->getMethod());
        $this->assertEquals('director', $query->getAttribute());
        $this->assertEquals([], $query->getValues());

        $query = Query::parse(Query::startsWith('director', 'Quentin')->toString());
        $this->assertEquals('startsWith', $query->getMethod());
        $this->assertEquals('director', $query->getAttribute());
        $this->assertEquals(['Quentin'], $query->getValues());

        $query = Query::parse(Query::endsWith('director', 'Tarantino')->toString());
        $this->assertEquals('endsWith', $query->getMethod());
        $this->assertEquals('director', $query->getAttribute());
        $this->assertEquals(['Tarantino'], $query->getValues());

        $query = Query::parse(Query::select(['title', 'director'])->toString());
        $this->assertEquals('select', $query->getMethod());
        $this->assertEquals(null, $query->getAttribute());
        $this->assertEquals(['title', 'director'], $query->getValues());

        // Test new date query wrapper methods parsing
        $query = Query::parse(Query::createdBefore('2023-01-01T00:00:00.000Z')->toString());
        $this->assertEquals('lessThan', $query->getMethod());
        $this->assertEquals('$createdAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z'], $query->getValues());

        $query = Query::parse(Query::createdAfter('2023-01-01T00:00:00.000Z')->toString());
        $this->assertEquals('greaterThan', $query->getMethod());
        $this->assertEquals('$createdAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z'], $query->getValues());

        $query = Query::parse(Query::updatedBefore('2023-12-31T23:59:59.999Z')->toString());
        $this->assertEquals('lessThan', $query->getMethod());
        $this->assertEquals('$updatedAt', $query->getAttribute());
        $this->assertEquals(['2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::parse(Query::updatedAfter('2023-12-31T23:59:59.999Z')->toString());
        $this->assertEquals('greaterThan', $query->getMethod());
        $this->assertEquals('$updatedAt', $query->getAttribute());
        $this->assertEquals(['2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::parse(Query::createdBetween('2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z')->toString());
        $this->assertEquals('between', $query->getMethod());
        $this->assertEquals('$createdAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::parse(Query::updatedBetween('2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z')->toString());
        $this->assertEquals('between', $query->getMethod());
        $this->assertEquals('$updatedAt', $query->getAttribute());
        $this->assertEquals(['2023-01-01T00:00:00.000Z', '2023-12-31T23:59:59.999Z'], $query->getValues());

        $query = Query::parse(Query::between('age', 15, 18)->toString());
        $this->assertEquals('between', $query->getMethod());
        $this->assertEquals('age', $query->getAttribute());
        $this->assertEquals([15, 18], $query->getValues());

        $query = Query::parse(Query::between('lastUpdate', 'DATE1', 'DATE2')->toString());
        $this->assertEquals('between', $query->getMethod());
        $this->assertEquals('lastUpdate', $query->getAttribute());
        $this->assertEquals(['DATE1', 'DATE2'], $query->getValues());

        $query = Query::parse(Query::equal('attr', [1])->toString());
        $this->assertCount(1, $query->getValues());
        $this->assertEquals('attr', $query->getAttribute());
        $this->assertEquals([1], $query->getValues());

        $query = Query::parse(Query::equal('attr', [0])->toString());
        $this->assertCount(1, $query->getValues());
        $this->assertEquals('attr', $query->getAttribute());
        $this->assertEquals([0], $query->getValues());

        $query = Query::parse(Query::equal('1', ['[Hello] World'])->toString());
        $this->assertCount(1, $query->getValues());
        $this->assertEquals('1', $query->getAttribute());
        $this->assertEquals(['[Hello] World'], $query->getValues());

        $query = Query::parse(Query::equal('1', ['Hello /\\ World'])->toString());
        $this->assertCount(1, $query->getValues());
        $this->assertEquals(1, $query->getAttribute());
        $this->assertEquals(['Hello /\ World'], $query->getValues());

        $json = Query::or([
            Query::equal('actors', ['Brad Pitt']),
            Query::equal('actors', ['Johnny Depp'])
        ])->toString();

        $query = Query::parse($json);

        /** @var array<Query> $queries */
        $queries = $query->getValues();
        $this->assertCount(2, $query->getValues());
        $this->assertEquals(Query::TYPE_OR, $query->getMethod());
        $this->assertEquals(Query::TYPE_EQUAL, $queries[0]->getMethod());
        $this->assertEquals('actors', $queries[0]->getAttribute());
        $this->assertEquals($json, '{"method":"or","values":[{"method":"equal","attribute":"actors","values":["Brad Pitt"]},{"method":"equal","attribute":"actors","values":["Johnny Depp"]}]}');

        try {
            Query::parse('{"method":["equal"],"attribute":["title"],"values":["test"]}');
            $this->fail('Failed to throw exception');
        } catch (QueryException $e) {
            $this->assertEquals('Invalid query method. Must be a string, got array', $e->getMessage());
        }

        try {
            Query::parse('{"method":"equal","attribute":["title"],"values":["test"]}');
            $this->fail('Failed to throw exception');
        } catch (QueryException $e) {
            $this->assertEquals('Invalid query attribute. Must be a string, got array', $e->getMessage());
        }

        try {
            Query::parse('{"method":"equal","attribute":"title","values":"test"}');
            $this->fail('Failed to throw exception');
        } catch (QueryException $e) {
            $this->assertEquals('Invalid query values. Must be an array, got string', $e->getMessage());
        }

        try {
            Query::parse('false');
            $this->fail('Failed to throw exception');
        } catch (QueryException $e) {
            $this->assertEquals('Invalid query. Must be an array, got boolean', $e->getMessage());
        }

        // Test orderRandom query parsing
        $query = Query::parse(Query::orderRandom()->toString());
        $this->assertEquals('orderRandom', $query->getMethod());
        $this->assertEquals('', $query->getAttribute());
        $this->assertEquals([], $query->getValues());
    }

    public function testParseNestedStringValues(): void
    {
        // Some clients serialize the children of a logical query as JSON strings
        // rather than nested objects. Parsing must handle that without a TypeError.
        $json = (string) \json_encode([
            'method' => Query::TYPE_OR,
            'values' => [
                Query::equal('actors', ['Brad Pitt'])->toString(),
                Query::equal('actors', ['Johnny Depp'])->toString(),
            ],
        ]);

        $query = Query::parse($json);

        /** @var array<Query> $queries */
        $queries = $query->getValues();
        $this->assertEquals(Query::TYPE_OR, $query->getMethod());
        $this->assertCount(2, $queries);
        $this->assertEquals(Query::TYPE_EQUAL, $queries[0]->getMethod());
        $this->assertEquals('actors', $queries[0]->getAttribute());
        $this->assertEquals(['Brad Pitt'], $queries[0]->getValues());
        $this->assertEquals(Query::TYPE_EQUAL, $queries[1]->getMethod());
        $this->assertEquals(['Johnny Depp'], $queries[1]->getValues());

        // A nested value that is neither an array nor a string is a clean
        // QueryException, never an uncaught TypeError.
        try {
            Query::parse((string) \json_encode(['method' => Query::TYPE_OR, 'values' => [123]]));
            $this->fail('Failed to throw exception');
        } catch (QueryException $e) {
            $this->assertEquals('Invalid nested query. Must be an array or string, got integer', $e->getMessage());
        }
    }

    public function testIsMethod(): void
    {
        $this->assertTrue(Query::isMethod('equal'));
        $this->assertTrue(Query::isMethod('notEqual'));
        $this->assertTrue(Query::isMethod('lessThan'));
        $this->assertTrue(Query::isMethod('lessThanEqual'));
        $this->assertTrue(Query::isMethod('greaterThan'));
        $this->assertTrue(Query::isMethod('greaterThanEqual'));
        $this->assertTrue(Query::isMethod('contains'));
        $this->assertTrue(Query::isMethod('notContains'));
        $this->assertTrue(Query::isMethod('search'));
        $this->assertTrue(Query::isMethod('notSearch'));
        $this->assertTrue(Query::isMethod('startsWith'));
        $this->assertTrue(Query::isMethod('notStartsWith'));
        $this->assertTrue(Query::isMethod('endsWith'));
        $this->assertTrue(Query::isMethod('notEndsWith'));
        $this->assertTrue(Query::isMethod('orderDesc'));
        $this->assertTrue(Query::isMethod('orderAsc'));
        $this->assertTrue(Query::isMethod('limit'));
        $this->assertTrue(Query::isMethod('offset'));
        $this->assertTrue(Query::isMethod('cursorAfter'));
        $this->assertTrue(Query::isMethod('cursorBefore'));
        $this->assertTrue(Query::isMethod('orderRandom'));
        $this->assertTrue(Query::isMethod('isNull'));
        $this->assertTrue(Query::isMethod('isNotNull'));
        $this->assertTrue(Query::isMethod('between'));
        $this->assertTrue(Query::isMethod('notBetween'));
        $this->assertTrue(Query::isMethod('select'));
        $this->assertTrue(Query::isMethod('or'));
        $this->assertTrue(Query::isMethod('and'));

        $this->assertTrue(Query::isMethod(Query::TYPE_EQUAL));
        $this->assertTrue(Query::isMethod(Query::TYPE_NOT_EQUAL));
        $this->assertTrue(Query::isMethod(Query::TYPE_LESSER));
        $this->assertTrue(Query::isMethod(Query::TYPE_LESSER_EQUAL));
        $this->assertTrue(Query::isMethod(Query::TYPE_GREATER));
        $this->assertTrue(Query::isMethod(Query::TYPE_GREATER_EQUAL));
        $this->assertTrue(Query::isMethod(Query::TYPE_CONTAINS));
        $this->assertTrue(Query::isMethod(Query::TYPE_NOT_CONTAINS));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_SEARCH));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_NOT_SEARCH));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_STARTS_WITH));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_NOT_STARTS_WITH));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_ENDS_WITH));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_NOT_ENDS_WITH));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_ORDER_ASC));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_ORDER_DESC));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_LIMIT));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_OFFSET));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_CURSOR_AFTER));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_CURSOR_BEFORE));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_ORDER_RANDOM));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_IS_NULL));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_IS_NOT_NULL));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_BETWEEN));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_NOT_BETWEEN));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_SELECT));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_OR));
        $this->assertTrue(Query::isMethod(QUERY::TYPE_AND));

        $this->assertFalse(Query::isMethod('invalid'));
        $this->assertFalse(Query::isMethod('lte '));
    }

    public function testNewQueryTypesInTypesArray(): void
    {
        $this->assertContains(Query::TYPE_NOT_CONTAINS, Query::TYPES);
        $this->assertContains(Query::TYPE_NOT_SEARCH, Query::TYPES);
        $this->assertContains(Query::TYPE_NOT_STARTS_WITH, Query::TYPES);
        $this->assertContains(Query::TYPE_NOT_ENDS_WITH, Query::TYPES);
        $this->assertContains(Query::TYPE_NOT_BETWEEN, Query::TYPES);
        $this->assertContains(Query::TYPE_ORDER_RANDOM, Query::TYPES);
    }

    public function testFingerprint(): void
    {
        $equalAlice = '{"method":"equal","attribute":"name","values":["Alice"]}';
        $equalBob = '{"method":"equal","attribute":"name","values":["Bob"]}';
        $equalEmail = '{"method":"equal","attribute":"email","values":["a@b.c"]}';
        $notEqualAlice = '{"method":"notEqual","attribute":"name","values":["Alice"]}';
        $gtAge18 = '{"method":"greaterThan","attribute":"age","values":[18]}';
        $gtAge42 = '{"method":"greaterThan","attribute":"age","values":[42]}';

        // Same shape, different values produce the same fingerprint
        $a = Query::fingerprint([$equalAlice, $gtAge18]);
        $b = Query::fingerprint([$equalBob, $gtAge42]);
        $this->assertSame($a, $b);

        // Different attribute produces different fingerprint
        $c = Query::fingerprint([$equalEmail, $gtAge18]);
        $this->assertNotSame($a, $c);

        // Different method produces different fingerprint
        $d = Query::fingerprint([$notEqualAlice, $gtAge18]);
        $this->assertNotSame($a, $d);

        // Order-independent
        $e = Query::fingerprint([$gtAge18, $equalAlice]);
        $this->assertSame($a, $e);

        // Accepts parsed Query objects
        $parsed = [Query::equal('name', ['Alice']), Query::greaterThan('age', 18)];
        $f = Query::fingerprint($parsed);
        $this->assertSame($a, $f);

        // Empty array returns deterministic hash
        $this->assertSame(\md5(''), Query::fingerprint([]));
    }

    public function testFingerprintNestedLogicalQueries(): void
    {
        // AND queries with different inner shapes produce different fingerprints
        $andEqName = Query::and([Query::equal('name', ['Alice'])]);
        $andEqEmail = Query::and([Query::equal('email', ['a@b.c'])]);
        $this->assertNotSame(Query::fingerprint([$andEqName]), Query::fingerprint([$andEqEmail]));

        // AND queries with same inner shape produce the same fingerprint (values differ)
        $andEqNameBob = Query::and([Query::equal('name', ['Bob'])]);
        $this->assertSame(Query::fingerprint([$andEqName]), Query::fingerprint([$andEqNameBob]));

        // Order of children inside a logical query does not matter
        $andA = Query::and([Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $andB = Query::and([Query::greaterThan('age', 42), Query::equal('name', ['Bob'])]);
        $this->assertSame(Query::fingerprint([$andA]), Query::fingerprint([$andB]));

        // AND of two filters differs from OR of the same two filters
        $orA = Query::or([Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $this->assertNotSame(Query::fingerprint([$andA]), Query::fingerprint([$orA]));

        // AND with one child differs from AND with two children
        $andOne = Query::and([Query::equal('name', ['Alice'])]);
        $andTwo = Query::and([Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $this->assertNotSame(Query::fingerprint([$andOne]), Query::fingerprint([$andTwo]));

        // elemMatch attribute matters: same inner shape on different fields must NOT collide
        $elemTags = new Query(Query::TYPE_ELEM_MATCH, 'tags', [Query::equal('name', ['php'])]);
        $elemCategories = new Query(Query::TYPE_ELEM_MATCH, 'categories', [Query::equal('name', ['php'])]);
        $this->assertNotSame(Query::fingerprint([$elemTags]), Query::fingerprint([$elemCategories]));

        // elemMatch values-only change (same field, same child shape) still collides — as expected
        $elemTagsOther = new Query(Query::TYPE_ELEM_MATCH, 'tags', [Query::equal('name', ['js'])]);
        $this->assertSame(Query::fingerprint([$elemTags]), Query::fingerprint([$elemTagsOther]));
    }

    public function testFingerprintRejectsInvalidElements(): void
    {
        $this->expectException(QueryException::class);
        Query::fingerprint([42]);
    }

    public function testShape(): void
    {
        // Leaf queries
        $this->assertSame('equal:name', Query::equal('name', ['Alice'])->shape());
        $this->assertSame('greaterThan:age', Query::greaterThan('age', 18)->shape());

        // Logical with empty attribute
        $and = Query::and([Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $this->assertSame('and:(equal:name|greaterThan:age)', $and->shape());

        // elemMatch preserves the attribute (the field being matched)
        $elem = new Query(Query::TYPE_ELEM_MATCH, 'tags', [Query::equal('name', ['php'])]);
        $this->assertSame('elemMatch:tags(equal:name)', $elem->shape());

        // Deeply nested — iterative traversal must match recursive result
        $deep = Query::and([
            Query::or([
                Query::equal('a', ['x']),
                Query::and([
                    Query::equal('b', ['y']),
                    Query::lessThan('c', 5),
                ]),
            ]),
            Query::greaterThan('d', 10),
        ]);
        $this->assertSame(
            'and:(greaterThan:d|or:(and:(equal:b|lessThan:c)|equal:a))',
            $deep->shape(),
        );
    }
}
