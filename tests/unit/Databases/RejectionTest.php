<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Rejection;
use Appwrite\Extend\Exception;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Databases\Fakes\DriverException;
use Throwable;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Mongo\Exception as MongoException;

final class RejectionTest extends TestCase
{
    /**
     * @return Iterator<string, array{Throwable, Rejection}>
     */
    public static function rejectedValueProvider(): Iterator
    {
        yield 'MariaDB unknown column' => [new NotFoundException('Attribute not found', 0, new DriverException('42S22', 1054, "Unknown column 's.x' in 'where clause'")), Rejection::Column];
        yield 'PostgreSQL undefined column' => [new NotFoundException('Attribute not found', 0, new DriverException('42703', 7, 'ERROR: column "x" does not exist')), Rejection::Column];
    }

    #[DataProvider('rejectedValueProvider')]
    public function testClassifiesValuesTheEngineRejected(Throwable $error, Rejection $expected): void
    {
        $this->assertSame($expected, Rejection::fromThrowable($error));
    }

    /**
     * @return Iterator<string, array{Throwable}>
     */
    public static function unrelatedErrorProvider(): Iterator
    {
        yield 'MySQL regex timeout' => [new DriverException('HY000', 3699, 'Timeout exceeded in regular expression match.')];
        yield 'MySQL regex internal error' => [new DriverException('HY000', 3687, 'Internal error in regular expression library.')];
        yield 'MySQL lost connection' => [new DriverException('HY000', 2006, 'MySQL server has gone away')];
        yield 'MySQL duplicate entry' => [new DriverException('23000', 1062, "Duplicate entry 'a' for key 'PRIMARY'")];
        yield 'PostgreSQL internal error' => [new DriverException('XX000', 7, 'ERROR: could not open relation')];
        yield 'PostgreSQL out of range' => [new DriverException('22003', 7, 'ERROR: value out of range: overflow')];
        yield 'driver error without a driver code' => [new DriverException('HY000', null, 'General error')];
        yield 'MongoDB bad value' => [new MongoException('cannot compare to undefined', 2)];
        yield 'MongoDB duplicate key' => [new MongoException('E11000 duplicate key error', 11000)];
        yield 'database exception' => [new QueryException('Invalid query')];
        yield 'missing table' => [new NotFoundException('Collection not found', 0, new DriverException('42S02', 1146, "Table 'appwrite.t' doesn't exist"))];
        yield 'not found without a driver cause' => [new NotFoundException('Attribute not found')];
        yield 'not found caused by a regex error' => [new NotFoundException('Attribute not found', 0, new DriverException('HY000', 3691, 'Mismatched parenthesis'))];
        yield 'runtime exception' => [new RuntimeException('Something broke', 3691)];
    }

    #[DataProvider('unrelatedErrorProvider')]
    public function testLeavesOtherErrorsUnclassified(Throwable $error): void
    {
        $this->assertNull(Rejection::fromThrowable($error));
    }

    public function testColumnIsAnInvalidQuery(): void
    {
        $this->assertSame(Exception::GENERAL_QUERY_INVALID, Rejection::Column->getType());
        $this->assertSame(400, new Exception(Rejection::Column->getType(), Rejection::Column->getMessage())->getCode());
    }
}
