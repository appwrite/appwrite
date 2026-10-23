<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\Maintenance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, string}>
     */
    public static function runs(): \Iterator
    {
        yield 'hourly after the start time' => ['2026-10-23 14:20:00', 3600, '2026-10-23 15:00:00'];
        yield 'daily after the start time' => ['2026-10-23 14:20:00', 86400, '2026-10-24 12:00:00'];
        yield 'every 8 hours after the start time' => ['2026-10-23 14:20:00', 28800, '2026-10-23 20:00:00'];
        yield 'hourly before the start time' => ['2026-10-23 11:30:00', 3600, '2026-10-23 12:00:00'];
        yield 'hourly on a run' => ['2026-10-23 15:00:00', 3600, '2026-10-23 16:00:00'];
        yield 'daily on a run' => ['2026-10-23 12:00:00', 86400, '2026-10-24 12:00:00'];
    }

    #[DataProvider('runs')]
    public function testFirstRunIsTheNextStepOnTheStartTimeGrid(string $now, int $interval, string $expected): void
    {
        $now = new \DateTime($now, new \DateTimeZone('UTC'));

        $next = Maintenance::next($now, '12:00', $interval);
        $delay = $next->getTimestamp() - $now->getTimestamp();

        $this->assertSame($expected, $next->format('Y-m-d H:i:s'));
        $this->assertGreaterThan(0, $delay);
        $this->assertLessThanOrEqual($interval, $delay);
    }
}
