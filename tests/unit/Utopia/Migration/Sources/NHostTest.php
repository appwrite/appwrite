<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Migration\Sources;

use Appwrite\Utopia\Migration\Sources\NHost;
use PHPUnit\Framework\TestCase;

final class NHostTest extends TestCase
{
    public function testBuildsHostsFromSubdomainAndRegion(): void
    {
        $this->assertSame('abc.db.eu-central-1.nhost.run', NHost::databaseHost('abc', 'eu-central-1'));
        $this->assertSame('abc.storage.eu-central-1.nhost.run', NHost::storageHost('abc', 'eu-central-1'));
    }

    public function testDatabaseConnectsToTheGivenAddress(): void
    {
        // The hostname cannot resolve, so reaching the address proves no DNS lookup happened
        $nhost = new NHost('abc', 'invalid', 'secret', 'db', 'user', 'password', '1', '127.0.0.1');

        $this->expectExceptionMessage('"127.0.0.1"');

        $nhost->getDatabase();
    }

    public function testDatabaseRefusesNameWithSeparators(): void
    {
        $nhost = new NHost('abc', 'invalid', 'secret', 'db;hostaddr=127.0.0.2', 'user', 'password', '1', '127.0.0.1');

        $this->expectExceptionMessage('Invalid NHost subdomain, region or database name.');

        $nhost->getDatabase();
    }
}
