<?php

namespace Utopia\Migration\Tests\Sources;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Sources\NHost;

final class NHostTest extends TestCase
{
    public function testDatabaseHostJoinsSubdomainAndRegion(): void
    {
        $nhost = new NHost('abcdefghijklmnopqrst', 'eu-central-1', 'secret', 'abcdefghijklmnopqrst', 'postgres', 'password');

        $this->assertSame('abcdefghijklmnopqrst.db.eu-central-1.nhost.run', $nhost->getDatabaseHost());
    }
}
