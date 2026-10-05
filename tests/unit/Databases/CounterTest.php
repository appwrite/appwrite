<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Counter;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
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

    public function testCountingADefinitionReadsNoMagicProperties(): void
    {
        $reads = new \ArrayObject();
        $attribute = static fn (string $key, ColumnType $type, bool $array = false): Attribute => new class ($reads, $key, $type, $array) extends Attribute {
            /**
             * @param \ArrayObject<int, string> $reads
             */
            public function __construct(private readonly \ArrayObject $reads, string $key, ColumnType $type, bool $array)
            {
                parent::__construct($key, $type, array: $array);
            }

            public function __get(string $name): mixed
            {
                $this->reads->append('attribute.' . $name);

                return parent::__get($name);
            }
        };
        $definition = new class ($reads, self::COLLECTION, [$attribute('ratio', ColumnType::Double), $attribute('scores', ColumnType::Integer, true), $attribute('count', ColumnType::Integer)]) extends Collection {
            /**
             * @param \ArrayObject<int, string> $reads
             * @param array<Attribute> $attributes
             */
            public function __construct(private readonly \ArrayObject $reads, string $id, array $attributes)
            {
                parent::__construct(id: $id, attributes: $attributes);
            }

            public function __get(string $name): mixed
            {
                $this->reads->append('collection.' . $name);

                return parent::__get($name);
            }
        };
        $database = new class (new Memory(), new Cache(new None()), $definition) extends Database {
            public function __construct(Adapter $adapter, Cache $cache, private readonly Collection $definition)
            {
                parent::__construct($adapter, $cache);
            }

            public function getCollection(string $id): Collection
            {
                return $this->definition;
            }
        };

        $integer = Counter::of($database, self::COLLECTION, 'count');
        $array = Counter::of($database, self::COLLECTION, 'scores');
        $double = Counter::of($database, self::COLLECTION, 'ratio');

        $this->assertSame([], $reads->getArrayCopy());
        $this->assertSame(10, $integer->maximum(10.5));
        $this->assertEqualsWithDelta(10.5, $array->maximum(10.5), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(10.5, $double->maximum(10.5), PHP_FLOAT_EPSILON);
    }

    public function testAFractionalChangeValueOnAnIntegerIsRefusedAsAnInvalidArgument(): void
    {
        $counter = Counter::of($this->database, self::COLLECTION, 'count');

        try {
            $counter->assertChange(1.5, 'increment', 'column', 'count');
            $this->fail('a fractional change value on an integer must be refused');
        } catch (Exception $exception) {
            $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $exception->getType());
            $this->assertSame('Value must be a whole number to increment the integer column "count".', $exception->getMessage());
        }
    }

    public function testAcceptedChangeValuesPassTheAssertion(): void
    {
        Counter::of($this->database, self::COLLECTION, 'count')->assertChange(2, 'decrement', 'attribute', 'count');
        Counter::of($this->database, self::COLLECTION, 'ratio')->assertChange(1.5, 'increment', 'attribute', 'ratio');

        $this->addToAssertionCount(2);
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
