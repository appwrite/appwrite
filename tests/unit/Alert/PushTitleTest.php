<?php

declare(strict_types=1);

namespace Tests\Unit\Alert;

use Appwrite\Alert\Status;
use Appwrite\Platform\Modules\Alerts\Workers\Alerts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushTitleTest extends TestCase
{
    /**
     * @return \Iterator<string, array{Status, string, string, string}>
     */
    public static function titles(): \Iterator
    {
        yield 'firing leads with its severity' => [Status::Firing, 'critical', 'Pulse', '[Critical] Requests spike on Pulse'];
        yield 'warning' => [Status::Firing, 'warning', 'Pulse', '[Warning] Requests spike on Pulse'];
        yield 'from before severities' => [Status::Firing, '', 'Pulse', '[Warning] Requests spike on Pulse'];
        yield 'unknown severity' => [Status::Firing, 'page', 'Pulse', '[Warning] Requests spike on Pulse'];
        yield 'resolved, whatever its severity' => [Status::Ok, 'critical', 'Pulse', '[Resolved] Requests spike on Pulse'];
        yield 'no place' => [Status::Firing, 'error', '', '[Error] Requests spike'];
    }

    #[DataProvider('titles')]
    public function testTitle(Status $status, string $severity, string $place, string $expected): void
    {
        // Static, so the worker, whose actions need the app's constants, isn't built.
        $title = new \ReflectionMethod(Alerts::class, 'pushTitle');

        $this->assertSame($expected, $title->invoke(null, $status, $severity, 'Requests spike', $place));
    }
}
