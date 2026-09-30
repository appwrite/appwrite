<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Counter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

final class CounterTest extends TestCase
{
    private const string COLLECTION = 'counters';

    private Database $database;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = new Database(new Memory(), new Cache(new None()));
        $this->database
            ->setAuthorization($authorization)
            ->setDatabase('counter')
            ->setNamespace('counter_' . \uniqid());
        $this->database->create();
        $this->database->createCollection(new Collection(
            id: self::COLLECTION,
            attributes: [
                new Attribute('count', ColumnType::Integer),
                new Attribute('big', ColumnType::BigInteger),
                new Attribute('ratio', ColumnType::Double),
                new Attribute('scores', ColumnType::Integer, array: true),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ));
    }

    /**
     * @return iterable<string, array{int|float|string|null, int|float|string|null, int|float|string|null}>
     */
    public static function integerBounds(): iterable
    {
        yield 'fractional' => [10.5, 10, 11];
        yield 'negative fractional' => [-2.5, -3, -2];
        yield 'just under a whole number' => [9.999, 9, 10];
        yield 'whole float' => [10.0, 10, 10];
        yield 'integer' => [10, 10, 10];
        yield 'fractional text' => ['10.5', 10, 11];
        yield 'integer text' => ['10', '10', '10'];
        yield 'beyond the native integer range' => [1.0e19, '10000000000000000000', '10000000000000000000'];
        yield 'no bound' => [null, null, null];
    }

    #[DataProvider('integerBounds')]
    public function testAnIntegerAttributeRoundsAFractionalMaximumDownAndMinimumUp(int|float|string|null $bound, int|float|string|null $maximum, int|float|string|null $minimum): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $this->assertSame($maximum, $counter->maximum($bound));
        $this->assertSame($minimum, $counter->minimum($bound));
    }

    public function testABigIntegerAttributeRoundsItsBoundsLikeAnInteger(): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'big');

        $this->assertSame(10, $counter->maximum(10.5));
        $this->assertSame(11, $counter->minimum(10.5));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unroundedAttributes(): iterable
    {
        yield 'double' => ['ratio'];
        yield 'integer array' => ['scores'];
        yield 'undeclared' => ['missing'];
    }

    #[DataProvider('unroundedAttributes')]
    public function testOtherAttributesKeepTheirBoundsAndChangeValues(string $attribute): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, $attribute);

        $this->assertSame(10.5, $counter->maximum(10.5));
        $this->assertSame(10.5, $counter->minimum(10.5));
        $this->assertTrue($counter->acceptsChange(1.5));
        $this->assertSame(1.5, $counter->change(1.5));
    }

    public function testBoundsTheLibraryRefusesAnywayArePassedOnUnchanged(): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $this->assertSame(\INF, $counter->maximum(\INF));
        $this->assertSame('ten', $counter->minimum('ten'));
    }

    /**
     * @return iterable<string, array{int|float|string, bool}>
     */
    public static function integerChanges(): iterable
    {
        yield 'integer' => [2, true];
        yield 'whole float' => [2.0, true];
        yield 'integer text' => ['2', true];
        yield 'fractional' => [1.5, false];
        yield 'fractional text' => ['1.5', false];
        yield 'infinite' => [\INF, false];
    }

    #[DataProvider('integerChanges')]
    public function testAnIntegerAttributeAcceptsOnlyWholeChangeValues(int|float|string $value, bool $accepted): void
    {
        $this->assertSame($accepted, Counter::of($this->database, self::COLLECTION, 'count')->acceptsChange($value));
    }

    public function testAWholeChangeValueOnAnIntegerAttributeIsPassedAsAnInteger(): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $this->assertSame(2, $counter->change(2.0));
        $this->assertSame(2, $counter->change(2));
        $this->assertSame('2', $counter->change('2'));
    }

    public function testAFractionalMaximumOnAnIntegerBoundsTheIncrementAtItsWholePart(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'hits', 'count' => 8]));
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $increased = $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'count', $counter->change(2.0), $counter->maximum(10.5));
        $this->assertSame(10, $increased->getAttribute('count'));

        try {
            $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'count', 1, $counter->maximum(10.5));
            $this->fail('an increment past the whole part of the maximum must be refused');
        } catch (LimitException) {
        }

        $this->assertSame(10, $this->database->getDocument(self::COLLECTION, $document->getId())->getAttribute('count'));
    }

    public function testAFractionalMinimumOnAnIntegerBoundsTheDecrementAtItsWholePart(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'stock', 'count' => 10]));
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $decreased = $this->database->decreaseDocumentAttribute(self::COLLECTION, $document->getId(), 'count', 7, $counter->minimum(2.5));
        $this->assertSame(3, $decreased->getAttribute('count'));

        try {
            $this->database->decreaseDocumentAttribute(self::COLLECTION, $document->getId(), 'count', 1, $counter->minimum(2.5));
            $this->fail('a decrement past the whole part of the minimum must be refused');
        } catch (LimitException) {
        }

        $this->assertSame(3, $this->database->getDocument(self::COLLECTION, $document->getId())->getAttribute('count'));
    }

    public function testTheLibraryRefusesTheFractionalBoundItselfOnAnInteger(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'raw', 'count' => 8]));

        $this->expectException(TypeException::class);
        $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'count', 1, 10.5);
    }
}
