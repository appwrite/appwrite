<?php

declare(strict_types=1);

namespace Tests\Unit\Screenshots;

use Appwrite\Screenshots\Exception;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testPermanentOverMemoryBudget(): void
    {
        // The guard closes the page because the container's working set crossed
        // the budget, which the same page does on every capture.
        $exception = new Exception('Page exceeded the memory budget and was closed.', 507);

        $this->assertTrue($exception->isPermanent());
    }

    public function testPermanentBlockedNavigation(): void
    {
        $exception = new Exception("Navigation to 'http://169.254.169.254/' was blocked: the destination is not publicly routable.", 403);

        $this->assertTrue($exception->isPermanent());
    }

    public function testPermanentUnroutableHost(): void
    {
        // Checked before a page is opened, and answered with the same status as
        // a page that failed to render.
        $exception = new Exception("Host 'localhost' is not publicly routable.", 400);

        $this->assertTrue($exception->isPermanent());
    }

    public function testTimeoutIsNotPermanent(): void
    {
        $exception = new Exception('Timeout 60000ms exceeded.', 400);

        $this->assertFalse($exception->isPermanent());
    }

    public function testServiceFailureIsNotPermanent(): void
    {
        $exception = new Exception('Screenshot failed with status 502', 502);

        $this->assertFalse($exception->isPermanent());
    }
}
