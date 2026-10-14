<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database;

use Appwrite\Utopia\Database\ColumnRules;
use Appwrite\Utopia\Database\Operators;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Operator;

final class OperatorsTest extends TestCase
{
    public function testColumnRangeMatchesPlainUpdates(): void
    {
        $attribute = $this->integer('sm', 0, 100, 4);

        $this->assertNull(ColumnRules::describe($attribute, 100));
        $this->assertNull(ColumnRules::describe($attribute, 0));
        $this->assertSame(
            'Invalid document structure: Attribute "sm" has invalid format. Value must be a valid range between 0 and 100',
            ColumnRules::describe($attribute, 599),
        );
    }

    public function testIncrementAndMultiplyStayInsideColumnBounds(): void
    {
        $collection = $this->collection([
            $this->integer('sm', 0, 100, 4),
        ]);
        $row = new Document(['sm' => 99]);

        $increment = Operator::increment(500);
        $data = ['sm' => $increment];
        try {
            Operators::prepare($collection, $row, $data);
            $this->fail('increment past max should fail column validation');
        } catch (StructureException $e) {
            $this->assertStringContainsString('between 0 and 100', $e->getMessage());
        }
        $this->assertSame([500], $increment->getValues());

        $multiply = Operator::multiply(1000);
        $data = ['sm' => $multiply];
        $this->expectException(StructureException::class);
        Operators::prepare($collection, $row, $data);
    }

    public function testDecrementRespectsMinimum(): void
    {
        $collection = $this->collection([
            $this->integer('sm', 0, 100, 4),
        ]);

        $this->expectException(StructureException::class);
        $data = ['sm' => Operator::decrement(500)];
        Operators::prepare($collection, new Document(['sm' => 5]), $data);
    }

    public function testArrayAppendRespectsElementSize(): void
    {
        $collection = $this->collection([
            [
                'key' => 'arr',
                'type' => Database::VAR_STRING,
                'size' => 32,
                'array' => true,
                'required' => false,
                'signed' => true,
                'format' => '',
                'formatOptions' => [],
            ],
        ]);
        $row = new Document(['arr' => ['ok']]);

        $tooLong = ['arr' => Operator::arrayAppend([str_repeat('a', 40)])];
        try {
            Operators::prepare($collection, $row, $tooLong);
            $this->fail('array element longer than size should fail');
        } catch (StructureException $e) {
            $this->assertStringContainsString('32', $e->getMessage());
        }

        $fits = ['arr' => Operator::arrayAppend(['short'])];
        Operators::prepare($collection, $row, $fits);
        $this->assertSame(['short'], $fits['arr']->getValues());
    }

    public function testWideIntegerIncrementIsNotCappedAt32Bits(): void
    {
        $collection = $this->collection([
            $this->integer('big', \PHP_INT_MIN, \PHP_INT_MAX, 8),
        ]);
        $operator = Operator::increment(1);
        $data = ['big' => $operator];

        Operators::prepare($collection, new Document(['big' => 5000000000]), $data);

        $this->assertSame([1, \PHP_INT_MAX], $operator->getValues());
    }

    public function testInRangeIncrementDoesNotAttachACeiling(): void
    {
        $collection = $this->collection([
            $this->integer('sm', 0, 100, 4),
        ]);
        $operator = Operator::increment(1);
        $data = ['sm' => $operator];

        Operators::prepare($collection, new Document(['sm' => 99]), $data);

        $this->assertSame([1], $operator->getValues());
    }

    public function testNullColumnChecksOperandTypeOnly(): void
    {
        $collection = $this->collection([
            $this->integer('n', \PHP_INT_MIN, \PHP_INT_MAX, 8),
        ]);
        $row = new Document(['n' => null]);

        $this->assertNull(Operators::limit($collection, $row, 'n', 1, null, true));

        $this->expectException(StructureException::class);
        Operators::limit($collection, $row, 'n', 1.5, null, true);
    }

    public function testHugeOperandOnIntegerColumnIsRejected(): void
    {
        $collection = $this->collection([
            $this->integer('n', \PHP_INT_MIN, \PHP_INT_MAX, 8),
        ]);

        $this->expectException(StructureException::class);
        Operators::limit($collection, new Document(['n' => null]), 'n', 1e30, null, true);
    }

    public function testFloatAtSigned64BitCeilingIsRejected(): void
    {
        $collection = $this->collection([
            $this->integer('big', \PHP_INT_MIN, \PHP_INT_MAX, 8),
        ]);

        $this->expectException(StructureException::class);
        Operators::limit($collection, new Document(['big' => \PHP_INT_MAX]), 'big', 1, null, true);
    }

    public function testEvenIntegerDivisionStaysValid(): void
    {
        $collection = $this->collection([
            $this->integer('sm', 0, 100, 4),
        ]);
        $operator = Operator::divide(2);
        $data = ['sm' => $operator];

        Operators::prepare($collection, new Document(['sm' => 10]), $data);

        $this->assertSame([2], $operator->getValues());
    }

    public function testRequestClampIsCombinedWithTheColumn(): void
    {
        $collection = $this->collection([
            $this->integer('sm', 0, 100, 4),
        ]);
        $row = new Document(['sm' => 99]);

        $this->assertSame(50, Operators::limit($collection, $row, 'sm', 1, 50, true));
        $this->assertSame(100, Operators::limit($collection, $row, 'sm', 1, 500, true));
    }

    public function testResultPastColumnMaxIsRejected(): void
    {
        $collection = $this->collection([
            $this->integer('sm', 0, 100, 4),
        ]);

        $this->expectException(StructureException::class);
        Operators::limit($collection, new Document(['sm' => 99]), 'sm', 500, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function integer(string $key, int $min, int $max, int $size): array
    {
        return [
            'key' => $key,
            'type' => Database::VAR_INTEGER,
            'size' => $size,
            'signed' => true,
            'array' => false,
            'required' => false,
            'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
            'formatOptions' => ['min' => $min, 'max' => $max],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     */
    private function collection(array $attributes): Document
    {
        return new Document([
            '$id' => 'collection',
            'attributes' => \array_map(
                static fn (array $attribute) => new Document($attribute),
                $attributes,
            ),
        ]);
    }
}
