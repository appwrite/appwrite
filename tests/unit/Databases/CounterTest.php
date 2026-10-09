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
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Permission;
use Utopia\Database\Role;
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
        $this->database->createCollection(Collection::create(
            id: self::COLLECTION,
            attributes: [
                Attribute::integer('count'),
                Attribute::bigInteger('big'),
                Attribute::double('ratio'),
                Attribute::integer('scores', array: true),
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
        yield 'fractional text beyond float precision' => ['9007199254740993.5', 9007199254740993, 9007199254740994];
        yield 'negative fractional text beyond float precision' => ['-9007199254740993.5', -9007199254740994, -9007199254740993];
        yield 'negative fractional text' => ['-5.5', -6, -5];
        yield 'negative fraction of one text' => ['-0.5', -1, 0];
        yield 'fraction of one text' => ['0.5', 0, 1];
        yield 'whole decimal text' => ['10.000', 10, 10];
        yield 'leading zeros text' => ['0000000000000000000005.5', 5, 6];
        yield 'fractional text above the native integer range' => ['9223372036854775807.5', 9223372036854775807, '9223372036854775808'];
        yield 'fractional text below the native integer range' => ['-9223372036854775808.5', '-9223372036854775809', \PHP_INT_MIN];
        yield 'fractional text at the unsigned limit' => ['18446744073709551615.9', '18446744073709551615', '18446744073709551616'];
        yield 'exponent text' => ['1e3', 1000, 1000];
        yield 'capital exponent text' => ['1E3', 1000, 1000];
        yield 'fractional exponent text' => ['1.25e1', 12, 13];
        yield 'negative fractional exponent text' => ['-1.25e1', -13, -12];
        yield 'negative exponent text' => ['1e-3', 0, 1];
        yield 'negative value with a negative exponent text' => ['-1e-3', -1, 0];
        yield 'vanishing exponent text' => ['1e-999999999', 0, 1];
        yield 'negative value with a vanishing exponent text' => ['-1e-999999999', -1, 0];
        yield 'exponent beyond the native integer range text' => ['5e-9223372036854775808', 0, 1];
        yield 'zero exponent text beyond float precision' => ['9007199254740993.5e0', 9007199254740993, 9007199254740994];
        yield 'padded fractional text' => [' 10.5 ', 10, 11];
        yield 'fractional text padded with form feed and vertical tab' => ["\f10.5\v", 10, 11];
        yield 'signed fractional text' => ['+10.5', 10, 11];
        yield 'fraction without a whole part text' => ['.5', 0, 1];
        yield 'whole part without a fraction text' => ['5.', 5, 5];
        yield 'negative zero text' => ['-0.0', 0, 0];
        yield 'zero with a huge exponent text' => ['0e999999999', 0, 0];
        yield 'zero decimal with an exponent text' => ['0.000e50', 0, 0];
        yield 'negative zero with an exponent text' => ['-0e5', 0, 0];
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

        $this->assertEqualsWithDelta(10.5, $counter->maximum(10.5), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(10.5, $counter->minimum(10.5), PHP_FLOAT_EPSILON);
        $this->assertTrue($counter->acceptsChange(1.5));
        $this->assertEqualsWithDelta(1.5, $counter->change(1.5), PHP_FLOAT_EPSILON);
    }

    public function testBoundsTheLibraryRefusesAnywayArePassedOnUnchanged(): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $this->assertSame(\INF, $counter->maximum(\INF));
        $this->assertSame('ten', $counter->minimum('ten'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonNumericBounds(): iterable
    {
        yield 'empty' => [''];
        yield 'exponent without digits' => ['1e'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'digit separators' => ['1_000'];
        yield 'two decimal points' => ['1.2.3'];
        yield 'two signs' => ['--5'];
    }

    #[DataProvider('nonNumericBounds')]
    public function testNonNumericTextBoundsArePassedOnUnchanged(string $bound): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        $this->assertSame($bound, $counter->maximum($bound));
        $this->assertSame($bound, $counter->minimum($bound));
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

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function metadataAttributes(): iterable
    {
        yield 'integer' => ['count', 10];
        yield 'big integer as stored' => ['big', 10];
        yield 'double' => ['ratio', 10.5];
        yield 'integer array' => ['scores', 10.5];
        yield 'undeclared' => ['missing', 10.5];
    }

    #[DataProvider('metadataAttributes')]
    public function testCollectionMetadataDeclaresTheSameIntegerAttributes(string $attribute, int|float $maximum): void
    {
        $collection = new Document([
            '$id' => 'metadata',
            'attributes' => [
                new Document(['key' => 'count', 'type' => ColumnType::Integer->value]),
                new Document(['key' => 'big', 'type' => 'bigint']),
                new Document(['key' => 'ratio', 'type' => ColumnType::Double->value]),
                new Document(['key' => 'scores', 'type' => ColumnType::Integer->value, 'array' => true]),
            ],
        ]);

        $this->assertSame($maximum, Counter::from($collection, $attribute)->maximum(10.5));
    }

    public function testAMissingCollectionCountsLikeAnUndeclaredAttribute(): void
    {
        $counter = Counter::of($this->database, 'missing', 'count');

        $this->assertEqualsWithDelta(10.5, $counter->maximum(10.5), PHP_FLOAT_EPSILON);
        $this->assertTrue($counter->acceptsChange(1.5));
    }

    /**
     * Main's results on MariaDB and MySQL with database 7.4.1: the response carries the exact result and the column
     * stores it rounded half to even.
     *
     * @return iterable<string, array{bool, string, int, float, int|float|null, float, int}>
     */
    public static function fractionalChanges(): iterable
    {
        yield 'increase rounds a half down to even' => [true, 'count', 5, 1.5, null, 6.5, 6];
        yield 'increase rounds a half up to even' => [true, 'count', 6, 1.5, null, 7.5, 8];
        yield 'increase rounds below a half down' => [true, 'count', 5, 1.4, null, 6.4, 6];
        yield 'increase rounds above a half up' => [true, 'count', 5, 1.6, null, 6.6, 7];
        yield 'increase rounds a negative half to even' => [true, 'count', -10, 2.5, null, -7.5, -8];
        yield 'increase by a half that rounds back to the value' => [true, 'count', 4, 0.5, null, 4.5, 4];
        yield 'increase by a half that rounds up' => [true, 'count', 5, 0.5, null, 5.5, 6];
        yield 'increase within a fractional maximum' => [true, 'count', 5, 1.5, 6.5, 6.5, 6];
        yield 'increase within a whole maximum' => [true, 'count', 5, 1.5, 7, 6.5, 6];
        yield 'increase a big integer' => [true, 'big', 5, 2.5, null, 7.5, 8];
        yield 'decrease rounds a half up to even' => [false, 'count', 5, 1.5, null, 3.5, 4];
        yield 'decrease rounds a half down to even' => [false, 'count', 6, 1.5, null, 4.5, 4];
        yield 'decrease by less than a half keeps the value' => [false, 'count', 5, 0.4, null, 4.6, 5];
        yield 'decrease within a fractional minimum' => [false, 'count', 5, 1.5, 3.5, 3.5, 4];
        yield 'a whole float change returns a float' => [true, 'count', 8, 2.0, null, 10.0, 10];
    }

    #[DataProvider('fractionalChanges')]
    public function testAFractionalChangeOnAnIntegerMatchesMain(bool $increase, string $attribute, int $start, float $value, int|float|null $bound, float $returned, int $stored): void
    {
        $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'meter', $attribute => $start]));
        $counter = Counter::of($this->database, self::COLLECTION, $attribute);

        $document = $increase
            ? $counter->increase($this->database, self::COLLECTION, 'meter', $attribute, $value, $bound)
            : $counter->decrease($this->database, self::COLLECTION, 'meter', $attribute, $value, $bound);

        $this->assertSame($returned, $document->getAttribute($attribute), 'Main returned the exact result of a fractional change on an integer.');
        $this->assertSame($stored, $this->database->getDocument(self::COLLECTION, 'meter')->getAttribute($attribute), 'Main stored a fractional change on an integer rounded half to even.');
    }

    /**
     * @return iterable<string, array{bool, int|float}>
     */
    public static function fractionalChangesPastTheirBound(): iterable
    {
        yield 'increase past the maximum' => [true, 6];
        yield 'decrease past the minimum' => [false, 4];
    }

    #[DataProvider('fractionalChangesPastTheirBound')]
    public function testAFractionalChangePastItsBoundIsRefusedAndRolledBack(bool $increase, int|float $bound): void
    {
        $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'meter', 'count' => 5]));
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        try {
            $increase
                ? $counter->increase($this->database, self::COLLECTION, 'meter', 'count', 1.5, $bound)
                : $counter->decrease($this->database, self::COLLECTION, 'meter', 'count', 1.5, $bound);
            $this->fail('A fractional change past its bound must be refused as on main.');
        } catch (LimitException) {
        }

        $this->assertSame(5, $this->database->getDocument(self::COLLECTION, 'meter')->getAttribute('count'));
    }

    public function testAFractionalChangeOnAMissingDocumentIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        Counter::of($this->database, self::COLLECTION, 'count')->increase($this->database, self::COLLECTION, 'missing', 'count', 1.5, null);
    }

    /**
     * @return iterable<string, array{int|float}>
     */
    public static function nonPositiveChanges(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'negative fraction' => [-1.5];
    }

    #[DataProvider('nonPositiveChanges')]
    public function testANonPositiveChangeIsAnInvalidArgumentAsOnMain(int|float $value): void
    {
        $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'meter', 'count' => 5]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric and greater than 0');

        Counter::of($this->database, self::COLLECTION, 'count')->decrease($this->database, self::COLLECTION, 'meter', 'count', $value, null);
    }

    public function testAFractionalChangeOnADoubleIsPassedThrough(): void
    {
        $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'meter', 'ratio' => 5.25]));

        $document = Counter::of($this->database, self::COLLECTION, 'ratio')->increase($this->database, self::COLLECTION, 'meter', 'ratio', 1.5, null);

        $this->assertSame(6.75, $document->getAttribute('ratio'));
        $this->assertSame(6.75, $this->database->getDocument(self::COLLECTION, 'meter')->getAttribute('ratio'));
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

    public function testAFractionalTextMaximumBeyondFloatPrecisionBoundsTheIncrementAtItsWholePart(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'precise', 'big' => 9007199254740992]));
        $counter = Counter::of($this->database, self::COLLECTION, 'big');

        $increased = $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 1, $counter->maximum('9007199254740993.5'));
        $this->assertSame(9007199254740993, $increased->getAttribute('big'));

        try {
            $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 1, $counter->maximum('9007199254740993.5'));
            $this->fail('an increment past the whole part of the maximum must be refused');
        } catch (LimitException) {
        }

        $this->assertSame(9007199254740993, $this->database->getDocument(self::COLLECTION, $document->getId())->getAttribute('big'));
    }

    public function testAFractionalTextMinimumBeyondFloatPrecisionBoundsTheDecrementAtItsWholePart(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'precise', 'big' => -9007199254740992]));
        $counter = Counter::of($this->database, self::COLLECTION, 'big');

        $decreased = $this->database->decreaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 1, $counter->minimum('-9007199254740993.5'));
        $this->assertSame(-9007199254740993, $decreased->getAttribute('big'));

        try {
            $this->database->decreaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 1, $counter->minimum('-9007199254740993.5'));
            $this->fail('a decrement past the whole part of the minimum must be refused');
        } catch (LimitException) {
        }

        $this->assertSame(-9007199254740993, $this->database->getDocument(self::COLLECTION, $document->getId())->getAttribute('big'));
    }

    public function testAMaximumTextBeyondTheIntegerRangeAllowsTheIncrement(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'unbounded', 'big' => 5]));
        $counter = Counter::of($this->database, self::COLLECTION, 'big');

        $increased = $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 1, $counter->maximum('1e400'));

        $this->assertSame(6, $increased->getAttribute('big'));
    }

    public function testAMaximumTextBelowTheIntegerRangeRefusesTheIncrement(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'unreachable', 'big' => 5]));
        $counter = Counter::of($this->database, self::COLLECTION, 'big');

        try {
            $this->database->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 1, $counter->maximum('-1e400'));
            $this->fail('an increment above a maximum below the integer range must be refused');
        } catch (LimitException) {
        }

        $this->assertSame(5, $this->database->getDocument(self::COLLECTION, $document->getId())->getAttribute('big'));
    }

    public function testAMinimumTextWithAHugeExponentAllowsTheDecrement(): void
    {
        $document = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'deep', 'big' => 5]));
        $counter = Counter::of($this->database, self::COLLECTION, 'big');

        $decreased = $this->database->decreaseDocumentAttribute(self::COLLECTION, $document->getId(), 'big', 10, $counter->minimum('-1e999999999'));

        $this->assertSame(-5, $decreased->getAttribute('big'));
    }
}
