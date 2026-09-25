<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\AttributeBigInt;
use Appwrite\Utopia\Response\Model\ColumnBigInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Query\Schema\ColumnType;

/**
 * Whatever spelling a row carries, the bigint response models emit the one
 * public API type, so a legacy row and a row written today look the same to a
 * caller.
 */
final class BigIntModelTest extends TestCase
{
    /**
     * @return \Iterator<string, array{class-string<Model>}>
     */
    public static function models(): \Iterator
    {
        yield 'attribute' => [AttributeBigInt::class];
        yield 'column' => [ColumnBigInt::class];
    }

    /**
     * @return \Iterator<string, array{class-string<Model>, string|ColumnType}>
     */
    public static function storedTypes(): \Iterator
    {
        foreach (['attribute' => AttributeBigInt::class, 'column' => ColumnBigInt::class] as $label => $model) {
            yield $label . ' legacy spelling' => [$model, 'biginteger'];
            yield $label . ' persisted spelling' => [$model, 'bigint'];
            yield $label . ' column type' => [$model, ColumnType::BigInteger];
        }
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('storedTypes')]
    public function testTheModelEmitsThePublicBigIntType(string $model, string|ColumnType $storedType): void
    {
        $filtered = (new $model())->filter(new Document(['key' => 'pages', 'type' => $storedType]));

        $this->assertSame('bigint', $filtered->getAttribute('type'));
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function testTheModelLeavesAnUnrelatedTypeAlone(string $model): void
    {
        $filtered = (new $model())->filter(new Document(['key' => 'pages', 'type' => 'integer']));

        $this->assertSame('integer', $filtered->getAttribute('type'));
    }
}
