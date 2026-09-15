<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Pwned;

use Appwrite\Auth\Pwned\Mock;
use PHPUnit\Framework\TestCase;

final class MockTest extends TestCase
{
    public function testFixturesAreReportedAsBreached(): void
    {
        $adapter = new Mock();

        foreach (Mock::BREACHED as $password) {
            $this->assertTrue($adapter->isPwned($password), $password);
        }
    }

    public function testAnythingElseIsReportedAsSafe(): void
    {
        $adapter = new Mock();

        $this->assertFalse($adapter->isPwned('some-other-password'));
        $this->assertFalse($adapter->isPwned(''));
    }

    public function testMatchingIsExact(): void
    {
        $adapter = new Mock();
        $fixture = Mock::BREACHED[0];

        // A fixture that merely contains or resembles a breached one is not breached
        $this->assertFalse($adapter->isPwned(\strtoupper($fixture)));
        $this->assertFalse($adapter->isPwned($fixture . '!'));
        $this->assertFalse($adapter->isPwned(\substr($fixture, 1)));
    }
}
