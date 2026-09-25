<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database;

use Appwrite\Utopia\Database\Attribute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Schema\ColumnType;

final class AttributeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function doubles(): \Iterator
    {
        yield 'without a size' => [['key' => 'score', 'type' => ColumnType::Double->value]];
        yield 'with a size' => [['key' => 'score', 'type' => ColumnType::Double->value, 'size' => 5]];
        yield 'with a negative size' => [['key' => 'score', 'type' => ColumnType::Double->value, 'size' => -1]];
    }

    /**
     * createFloatColumn stores a double with size 0 and takes no size, so an
     * inline double has to resolve to the same whatever size it was sent with.
     *
     * @param array<string, mixed> $definition
     */
    #[DataProvider('doubles')]
    public function testDoubleResolvesToTheSizeOfTheDedicatedEndpoint(array $definition): void
    {
        $this->assertSame(
            ['type' => ColumnType::Double->value, 'format' => '', 'size' => 0],
            Attribute::resolve($definition)
        );
    }
}
