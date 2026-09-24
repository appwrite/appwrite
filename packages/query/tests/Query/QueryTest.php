<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\CursorDirection;
use Utopia\Query\Method;
use Utopia\Query\OrderDirection;
use Utopia\Query\Query;

class QueryTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $query = new Query('equal');
        $this->assertSame(Method::Equal, $query->getMethod());
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testConstructorWithAllParams(): void
    {
        $query = new Query('equal', 'name', ['John']);
        $this->assertSame(Method::Equal, $query->getMethod());
        $this->assertSame('name', $query->getAttribute());
        $this->assertSame(['John'], $query->getValues());
    }

    public function testConstructorOrderAscDefaultAttribute(): void
    {
        $query = new Query(Method::OrderAsc);
        $this->assertSame('', $query->getAttribute());
    }

    public function testConstructorOrderDescDefaultAttribute(): void
    {
        $query = new Query(Method::OrderDesc);
        $this->assertSame('', $query->getAttribute());
    }

    public function testConstructorOrderAscWithAttribute(): void
    {
        $query = new Query(Method::OrderAsc, 'name');
        $this->assertSame('name', $query->getAttribute());
    }

    public function testGetValue(): void
    {
        $query = new Query('equal', 'name', ['John', 'Jane']);
        $this->assertSame('John', $query->getValue());
    }

    public function testGetValueDefault(): void
    {
        $query = new Query('equal', 'name');
        $this->assertSame('fallback', $query->getValue('fallback'));
    }

    public function testGetValueDefaultNull(): void
    {
        $query = new Query('equal', 'name');
        $this->assertNull($query->getValue());
    }

    public function testSetMethod(): void
    {
        $query = new Query('equal', 'name', ['John']);
        $result = $query->setMethod('notEqual');
        $this->assertSame(Method::NotEqual, $query->getMethod());
        $this->assertSame($query, $result);
    }

    public function testSetAttribute(): void
    {
        $query = new Query('equal', 'name', ['John']);
        $result = $query->setAttribute('age');
        $this->assertSame('age', $query->getAttribute());
        $this->assertSame($query, $result);
    }

    public function testSetValues(): void
    {
        $query = new Query('equal', 'name', ['John']);
        $result = $query->setValues(['Jane', 'Doe']);
        $this->assertSame(['Jane', 'Doe'], $query->getValues());
        $this->assertSame($query, $result);
    }

    public function testSetValue(): void
    {
        $query = new Query('equal', 'name', ['John', 'Jane']);
        $result = $query->setValue('Only');
        $this->assertSame(['Only'], $query->getValues());
        $this->assertSame($query, $result);
    }

    public function testSetAttributeType(): void
    {
        $query = new Query('equal', 'name');
        $query->setAttributeType('string');
        $this->assertSame('string', $query->getAttributeType());
    }

    public function testOnArray(): void
    {
        $query = new Query('equal', 'tags', [['a', 'b']]);
        $this->assertFalse($query->onArray());
        $query->setOnArray(true);
        $this->assertTrue($query->onArray());
    }

    public function testMethodEnumValues(): void
    {
        $this->assertSame('ASC', OrderDirection::Asc->value);
        $this->assertSame('DESC', OrderDirection::Desc->value);
        $this->assertSame('RANDOM', OrderDirection::Random->value);
        $this->assertSame('after', CursorDirection::After->value);
        $this->assertSame('before', CursorDirection::Before->value);
    }

    public function testVectorMethodsAreVector(): void
    {
        $this->assertTrue(Method::VectorDot->isVector());
        $this->assertTrue(Method::VectorCosine->isVector());
        $this->assertTrue(Method::VectorEuclidean->isVector());
        $vectorMethods = array_filter(Method::cases(), fn (Method $m) => $m->isVector());
        $this->assertCount(3, $vectorMethods);
    }

    public function testAllMethodCasesAreValid(): void
    {
        $this->assertTrue(Query::isMethod(Method::Equal->value));
        $this->assertTrue(Query::isMethod(Method::Regex->value));
        $this->assertTrue(Query::isMethod(Method::And->value));
        $this->assertTrue(Query::isMethod(Method::Or->value));
        $this->assertTrue(Query::isMethod(Method::ElemMatch->value));
        $this->assertTrue(Query::isMethod(Method::VectorDot->value));
    }

    public function testEmptyValues(): void
    {
        $query = Query::equal('name', []);
        $this->assertSame([], $query->getValues());
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
        $andEqName = new Query(Method::And, '', [Query::equal('name', ['Alice'])]);
        $andEqEmail = new Query(Method::And, '', [Query::equal('email', ['a@b.c'])]);
        $this->assertNotSame(Query::fingerprint([$andEqName]), Query::fingerprint([$andEqEmail]));

        // AND queries with same inner shape produce the same fingerprint (values differ)
        $andEqNameBob = new Query(Method::And, '', [Query::equal('name', ['Bob'])]);
        $this->assertSame(Query::fingerprint([$andEqName]), Query::fingerprint([$andEqNameBob]));

        // Order of children inside a logical query does not matter
        $andA = new Query(Method::And, '', [Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $andB = new Query(Method::And, '', [Query::greaterThan('age', 42), Query::equal('name', ['Bob'])]);
        $this->assertSame(Query::fingerprint([$andA]), Query::fingerprint([$andB]));

        // AND of two filters differs from OR of the same two filters
        $orA = new Query(Method::Or, '', [Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $this->assertNotSame(Query::fingerprint([$andA]), Query::fingerprint([$orA]));

        // AND with one child differs from AND with two children
        $andOne = new Query(Method::And, '', [Query::equal('name', ['Alice'])]);
        $andTwo = new Query(Method::And, '', [Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $this->assertNotSame(Query::fingerprint([$andOne]), Query::fingerprint([$andTwo]));

        // elemMatch attribute matters: same inner shape on different fields must NOT collide
        $elemTags = new Query(Method::ElemMatch, 'tags', [Query::equal('name', ['php'])]);
        $elemCategories = new Query(Method::ElemMatch, 'categories', [Query::equal('name', ['php'])]);
        $this->assertNotSame(Query::fingerprint([$elemTags]), Query::fingerprint([$elemCategories]));

        // elemMatch values-only change (same field, same child shape) still collides — as expected
        $elemTagsOther = new Query(Method::ElemMatch, 'tags', [Query::equal('name', ['js'])]);
        $this->assertSame(Query::fingerprint([$elemTags]), Query::fingerprint([$elemTagsOther]));
    }

    public function testFingerprintRejectsInvalidElements(): void
    {
        $this->expectException(\Utopia\Query\Exception::class);
        Query::fingerprint([42]);
    }

    public function testShape(): void
    {
        // Leaf queries
        $this->assertSame('equal:name', Query::equal('name', ['Alice'])->shape());
        $this->assertSame('greaterThan:age', Query::greaterThan('age', 18)->shape());

        // Logical with empty attribute
        $and = new Query(Method::And, '', [Query::equal('name', ['Alice']), Query::greaterThan('age', 18)]);
        $this->assertSame('and:(equal:name|greaterThan:age)', $and->shape());

        // elemMatch preserves the attribute (the field being matched)
        $elem = new Query(Method::ElemMatch, 'tags', [Query::equal('name', ['php'])]);
        $this->assertSame('elemMatch:tags(equal:name)', $elem->shape());

        // Deeply nested — iterative traversal must match recursive result
        $deep = new Query(Method::And, '', [
            new Query(Method::Or, '', [
                Query::equal('a', ['x']),
                new Query(Method::And, '', [
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

    public function testMethodContainsNewTypes(): void
    {
        $this->assertSame(Method::Count, Method::from('count'));
        $this->assertSame(Method::Sum, Method::from('sum'));
        $this->assertSame(Method::Avg, Method::from('avg'));
        $this->assertSame(Method::Min, Method::from('min'));
        $this->assertSame(Method::Max, Method::from('max'));
        $this->assertSame(Method::GroupBy, Method::from('groupBy'));
        $this->assertSame(Method::Having, Method::from('having'));
        $this->assertSame(Method::Distinct, Method::from('distinct'));
        $this->assertSame(Method::Join, Method::from('join'));
        $this->assertSame(Method::LeftJoin, Method::from('leftJoin'));
        $this->assertSame(Method::RightJoin, Method::from('rightJoin'));
        $this->assertSame(Method::CrossJoin, Method::from('crossJoin'));
        $this->assertSame(Method::Union, Method::from('union'));
        $this->assertSame(Method::UnionAll, Method::from('unionAll'));
        $this->assertSame(Method::Raw, Method::from('raw'));
    }

    public function testIsMethodNewTypes(): void
    {
        $this->assertTrue(Query::isMethod('count'));
        $this->assertTrue(Query::isMethod('sum'));
        $this->assertTrue(Query::isMethod('avg'));
        $this->assertTrue(Query::isMethod('min'));
        $this->assertTrue(Query::isMethod('max'));
        $this->assertTrue(Query::isMethod('groupBy'));
        $this->assertTrue(Query::isMethod('having'));
        $this->assertTrue(Query::isMethod('distinct'));
        $this->assertTrue(Query::isMethod('join'));
        $this->assertTrue(Query::isMethod('leftJoin'));
        $this->assertTrue(Query::isMethod('rightJoin'));
        $this->assertTrue(Query::isMethod('crossJoin'));
        $this->assertTrue(Query::isMethod('union'));
        $this->assertTrue(Query::isMethod('unionAll'));
        $this->assertTrue(Query::isMethod('raw'));
    }

    public function testDistinctFactory(): void
    {
        $query = Query::distinct();
        $this->assertSame(Method::Distinct, $query->getMethod());
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testRawFactory(): void
    {
        $query = Query::raw('score > ?', [10]);
        $this->assertSame(Method::Raw, $query->getMethod());
        $this->assertSame('score > ?', $query->getAttribute());
        $this->assertSame([10], $query->getValues());
    }

    public function testUnionFactory(): void
    {
        $inner = [Query::equal('x', [1])];
        $query = Query::union($inner);
        $this->assertSame(Method::Union, $query->getMethod());
        $this->assertCount(1, $query->getValues());
    }

    public function testUnionAllFactory(): void
    {
        $inner = [Query::equal('x', [1])];
        $query = Query::unionAll($inner);
        $this->assertSame(Method::UnionAll, $query->getMethod());
    }
    //  ADDITIONAL EDGE CASES

    public function testMethodNoDuplicateValues(): void
    {
        $values = array_map(fn (Method $m) => $m->value, Method::cases());
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function testAggregateMethodsNoDuplicates(): void
    {
        $aggMethods = array_filter(Method::cases(), fn (Method $m) => $m->isAggregate());
        $values = array_map(fn (Method $m) => $m->value, $aggMethods);
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function testJoinMethodsNoDuplicates(): void
    {
        $joinMethods = array_filter(Method::cases(), fn (Method $m) => $m->isJoin());
        $values = array_map(fn (Method $m) => $m->value, $joinMethods);
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function testAggregateMethodsAreValidMethods(): void
    {
        $aggMethods = array_filter(Method::cases(), fn (Method $m) => $m->isAggregate());
        foreach ($aggMethods as $method) {
            $this->assertSame($method, Method::from($method->value));
        }
    }

    public function testJoinMethodsAreValidMethods(): void
    {
        $joinMethods = array_filter(Method::cases(), fn (Method $m) => $m->isJoin());
        foreach ($joinMethods as $method) {
            $this->assertSame($method, Method::from($method->value));
        }
    }

    public function testIsMethodCaseSensitive(): void
    {
        $this->assertFalse(Query::isMethod('COUNT'));
        $this->assertFalse(Query::isMethod('Sum'));
        $this->assertFalse(Query::isMethod('JOIN'));
        $this->assertFalse(Query::isMethod('DISTINCT'));
        $this->assertFalse(Query::isMethod('GroupBy'));
        $this->assertFalse(Query::isMethod('RAW'));
    }

    public function testRawFactoryEmptySql(): void
    {
        $query = Query::raw('');
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testRawFactoryEmptyBindings(): void
    {
        $query = Query::raw('1 = 1', []);
        $this->assertSame([], $query->getValues());
    }

    public function testRawFactoryMixedBindings(): void
    {
        $query = Query::raw('a = ? AND b = ? AND c = ?', ['str', 42, 3.14]);
        $this->assertSame(['str', 42, 3.14], $query->getValues());
    }

    public function testUnionIsNested(): void
    {
        $query = Query::union([Query::equal('x', [1])]);
        $this->assertTrue($query->isNested());
    }

    public function testUnionAllIsNested(): void
    {
        $query = Query::unionAll([Query::equal('x', [1])]);
        $this->assertTrue($query->isNested());
    }

    public function testDistinctNotNested(): void
    {
        $this->assertFalse(Query::distinct()->isNested());
    }

    public function testCountNotNested(): void
    {
        $this->assertFalse(Query::count()->isNested());
    }

    public function testGroupByNotNested(): void
    {
        $this->assertFalse(Query::groupBy(['a'])->isNested());
    }

    public function testJoinNotNested(): void
    {
        $this->assertFalse(Query::join('t', 'a', 'b')->isNested());
    }

    public function testRawNotNested(): void
    {
        $this->assertFalse(Query::raw('1=1')->isNested());
    }

    public function testHavingNested(): void
    {
        $this->assertTrue(Query::having([Query::equal('x', [1])])->isNested());
    }

    public function testCloneDeepCopiesHavingQueries(): void
    {
        $inner = Query::greaterThan('total', 5);
        $outer = Query::having([$inner]);
        $cloned = clone $outer;

        $clonedValues = $cloned->getValues();
        $this->assertNotSame($inner, $clonedValues[0]);
        $this->assertInstanceOf(Query::class, $clonedValues[0]);

        /** @var Query $clonedInner */
        $clonedInner = $clonedValues[0];
        $this->assertSame(Method::GreaterThan, $clonedInner->getMethod());
    }

    public function testCloneDeepCopiesUnionQueries(): void
    {
        $inner = Query::equal('x', [1]);
        $outer = Query::union([$inner]);
        $cloned = clone $outer;

        $clonedValues = $cloned->getValues();
        $this->assertNotSame($inner, $clonedValues[0]);
    }

    public function testCountEnumValue(): void
    {
        $this->assertSame('count', Method::Count->value);
    }

    public function testSumEnumValue(): void
    {
        $this->assertSame('sum', Method::Sum->value);
    }

    public function testAvgEnumValue(): void
    {
        $this->assertSame('avg', Method::Avg->value);
    }

    public function testMinEnumValue(): void
    {
        $this->assertSame('min', Method::Min->value);
    }

    public function testMaxEnumValue(): void
    {
        $this->assertSame('max', Method::Max->value);
    }

    public function testGroupByEnumValue(): void
    {
        $this->assertSame('groupBy', Method::GroupBy->value);
    }

    public function testHavingEnumValue(): void
    {
        $this->assertSame('having', Method::Having->value);
    }

    public function testDistinctEnumValue(): void
    {
        $this->assertSame('distinct', Method::Distinct->value);
    }

    public function testJoinEnumValue(): void
    {
        $this->assertSame('join', Method::Join->value);
    }

    public function testLeftJoinEnumValue(): void
    {
        $this->assertSame('leftJoin', Method::LeftJoin->value);
    }

    public function testRightJoinEnumValue(): void
    {
        $this->assertSame('rightJoin', Method::RightJoin->value);
    }

    public function testCrossJoinEnumValue(): void
    {
        $this->assertSame('crossJoin', Method::CrossJoin->value);
    }

    public function testUnionEnumValue(): void
    {
        $this->assertSame('union', Method::Union->value);
    }

    public function testUnionAllEnumValue(): void
    {
        $this->assertSame('unionAll', Method::UnionAll->value);
    }

    public function testRawEnumValue(): void
    {
        $this->assertSame('raw', Method::Raw->value);
    }

    public function testCountIsSpatialQueryFalse(): void
    {
        $this->assertFalse(Query::count()->isSpatialQuery());
    }

    public function testJoinIsSpatialQueryFalse(): void
    {
        $this->assertFalse(Query::join('t', 'a', 'b')->isSpatialQuery());
    }

    public function testDistinctIsSpatialQueryFalse(): void
    {
        $this->assertFalse(Query::distinct()->isSpatialQuery());
    }

    public function testToStringReturnsJson(): void
    {
        $json = Query::equal('name', ['John'])->toString();
        $decoded = \json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('equal', $decoded['method']);
        $this->assertSame('name', $decoded['attribute']);
        $this->assertSame(['John'], $decoded['values']);
    }

    public function testToStringWithNestedQuery(): void
    {
        $json = Query::and([Query::equal('x', [1])])->toString();
        $decoded = \json_decode($json, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        $this->assertSame('and', $decoded['method']);
        $this->assertIsArray($decoded['values']);
        $this->assertCount(1, $decoded['values']);
        /** @var array<string, mixed> $inner */
        $inner = $decoded['values'][0];
        $this->assertSame('equal', $inner['method']);
    }

    public function testToStringThrowsOnInvalidJson(): void
    {
        // Verify that toString returns valid JSON for complex queries
        $query = Query::and([
            Query::or([
                Query::equal('a', [1]),
                Query::greaterThan('b', 2),
            ]),
            Query::lessThan('c', 3),
        ]);
        $json = $query->toString();
        $this->assertJson($json);
    }

    public function testSetMethodWithEnum(): void
    {
        $query = new Query('equal');
        $query->setMethod(Method::GreaterThan);
        $this->assertSame(Method::GreaterThan, $query->getMethod());
    }

    public function testToArraySimpleFilter(): void
    {
        $array = Query::equal('age', [25])->toArray();
        $this->assertSame('equal', $array['method']);
        $this->assertSame('age', $array['attribute']);
        $this->assertSame([25], $array['values']);
    }

    public function testToArrayWithEmptyAttribute(): void
    {
        $array = Query::distinct()->toArray();
        $this->assertArrayNotHasKey('attribute', $array);
    }

    public function testToArrayNestedQuery(): void
    {
        $array = Query::and([Query::equal('x', [1])])->toArray();
        $this->assertIsArray($array['values']);
        $this->assertCount(1, $array['values']);
        /** @var array<string, mixed> $nested */
        $nested = $array['values'][0];
        $this->assertArrayHasKey('method', $nested);
        $this->assertArrayHasKey('attribute', $nested);
        $this->assertArrayHasKey('values', $nested);
        $this->assertSame('equal', $nested['method']);
    }

    public function testCompileOrderAsc(): void
    {
        $builder = new MySQLBuilder();
        $result = Query::orderAsc('name')->compile($builder);
        $this->assertStringContainsString('ASC', $result);
    }

    public function testCompileOrderDesc(): void
    {
        $builder = new MySQLBuilder();
        $result = Query::orderDesc('name')->compile($builder);
        $this->assertStringContainsString('DESC', $result);
    }

    public function testCompileLimit(): void
    {
        $builder = new MySQLBuilder();
        $result = Query::limit(10)->compile($builder);
        $this->assertStringContainsString('LIMIT ?', $result);
    }

    public function testCompileOffset(): void
    {
        $builder = new MySQLBuilder();
        $result = Query::offset(5)->compile($builder);
        $this->assertStringContainsString('OFFSET ?', $result);
    }

    public function testCompileAggregate(): void
    {
        $builder = new MySQLBuilder();
        $result = Query::count('*', 'total')->compile($builder);
        $this->assertStringContainsString('COUNT(*)', $result);
        $this->assertStringContainsString('total', $result);
    }

    public function testIsMethodReturnsFalseForGarbage(): void
    {
        $this->assertFalse(Query::isMethod('notAMethod'));
    }

    public function testIsMethodReturnsFalseForEmpty(): void
    {
        $this->assertFalse(Query::isMethod(''));
    }

    public function testJsonContainsFactory(): void
    {
        $query = Query::jsonContains('tags', 'php');
        $this->assertSame(Method::JsonContains, $query->getMethod());
        $this->assertSame('tags', $query->getAttribute());
        $this->assertSame(['php'], $query->getValues());
    }

    public function testJsonNotContainsFactory(): void
    {
        $query = Query::jsonNotContains('meta', 42);
        $this->assertSame(Method::JsonNotContains, $query->getMethod());
    }

    public function testJsonOverlapsFactory(): void
    {
        $query = Query::jsonOverlaps('tags', ['a', 'b']);
        $this->assertSame(Method::JsonOverlaps, $query->getMethod());
        $this->assertSame([['a', 'b']], $query->getValues());
    }

    public function testJsonPathFactory(): void
    {
        $query = Query::jsonPath('data', 'name', '=', 'test');
        $this->assertSame(Method::JsonPath, $query->getMethod());
        $this->assertSame(['name', '=', 'test'], $query->getValues());
    }

    public function testCoversFactory(): void
    {
        $query = Query::covers('zone', [1.0, 2.0]);
        $this->assertSame(Method::Covers, $query->getMethod());
    }

    public function testNotCoversFactory(): void
    {
        $query = Query::notCovers('zone', [1.0, 2.0]);
        $this->assertSame(Method::NotCovers, $query->getMethod());
    }

    public function testSpatialEqualsFactory(): void
    {
        $query = Query::spatialEquals('geom', [3.0, 4.0]);
        $this->assertSame(Method::SpatialEquals, $query->getMethod());
    }

    public function testNotSpatialEqualsFactory(): void
    {
        $query = Query::notSpatialEquals('geom', [3.0, 4.0]);
        $this->assertSame(Method::NotSpatialEquals, $query->getMethod());
    }

    public function testIsJsonMethod(): void
    {
        $this->assertTrue(Method::JsonContains->isJson());
        $this->assertTrue(Method::JsonNotContains->isJson());
        $this->assertTrue(Method::JsonOverlaps->isJson());
        $this->assertTrue(Method::JsonPath->isJson());
    }

    public function testIsJsonMethodFalseForNonJson(): void
    {
        $this->assertFalse(Method::Equal->isJson());
    }

    public function testIsSpatialMethodCovers(): void
    {
        $this->assertTrue(Method::Covers->isSpatial());
        $this->assertTrue(Method::NotCovers->isSpatial());
        $this->assertTrue(Method::SpatialEquals->isSpatial());
        $this->assertTrue(Method::NotSpatialEquals->isSpatial());
    }

    public function testIsSpatialMethodFalseForNonSpatial(): void
    {
        $this->assertFalse(Method::Equal->isSpatial());
    }

    public function testIsFilterMethod(): void
    {
        $this->assertTrue(Method::Equal->isFilter());
        $this->assertTrue(Method::NotEqual->isFilter());
    }

    public function testIsFilterMethodFalseForNonFilter(): void
    {
        $this->assertFalse(Method::OrderAsc->isFilter());
    }
}
