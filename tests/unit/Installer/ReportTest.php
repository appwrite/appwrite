<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

use Appwrite\Installer\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testOptedOut(): void
    {
        $this->assertFalse(Report::optedOut('', Report::ENVIRONMENT_PRODUCTION, false));
        $this->assertTrue(Report::optedOut('1', Report::ENVIRONMENT_PRODUCTION, false));
        $this->assertTrue(Report::optedOut('TRUE', Report::ENVIRONMENT_PRODUCTION, false));
        $this->assertTrue(Report::optedOut('', 'development', false));
        $this->assertTrue(Report::optedOut('', Report::ENVIRONMENT_PRODUCTION, true));
    }
}
