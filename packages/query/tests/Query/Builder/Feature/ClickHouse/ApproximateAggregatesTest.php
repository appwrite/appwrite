<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Exception\ValidationException;

class ApproximateAggregatesTest extends TestCase
{
    use AssertsBindingCount;

    public function testQuantilesEmitsMultipleLevels(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantiles([0.25, 0.5, 0.75], 'value')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT quantiles(0.25, 0.5, 0.75)(`value`) FROM `events`', $result->query);
    }

    public function testQuantilesWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantiles([0.25, 0.5, 0.75], 'value', 'qs')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT quantiles(0.25, 0.5, 0.75)(`value`) AS `qs` FROM `events`', $result->query);
    }

    public function testQuantilesRejectsEmptyLevels(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('quantiles() requires at least one level.');

        (new Builder())
            ->from('events')
            ->quantiles([], 'value');
    }

    public function testQuantilesRejectsOutOfRangeLevels(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('quantiles() levels must be in the range [0, 1].');

        (new Builder())
            ->from('events')
            ->quantiles([0.5, 1.5], 'value');
    }
}
