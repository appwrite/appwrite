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

    public function testDatabaseDsnConnectsOnTheGivenAddress(): void
    {
        $nhost = new NHost('abcdefghijklmnopqrst', 'eu-central-1', 'secret', 'abcdefghijklmnopqrst', 'postgres', 'password');
        $nhost->setDatabaseAddress('203.0.113.10');

        $this->assertSame('pgsql:host=abcdefghijklmnopqrst.db.eu-central-1.nhost.run;hostaddr=203.0.113.10;port=5432;dbname=abcdefghijklmnopqrst', $nhost->getDatabaseDsn());
    }

    public function testDatabaseDsnWithoutAnAddressResolvesTheHost(): void
    {
        $nhost = new NHost('abcdefghijklmnopqrst', 'eu-central-1', 'secret', 'abcdefghijklmnopqrst', 'postgres', 'password');

        $this->assertSame('pgsql:host=abcdefghijklmnopqrst.db.eu-central-1.nhost.run;port=5432;dbname=abcdefghijklmnopqrst', $nhost->getDatabaseDsn());
    }
}
