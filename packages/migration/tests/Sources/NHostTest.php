<?php

declare(strict_types=1);

namespace Utopia\Migration\Tests\Sources;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Sources\NHost;

final class NHostTest extends TestCase
{
    public function testAcceptsProjectCoordinates(): void
    {
        $source = new NHost('abcdefghijklmnop', 'eu-central-1', 'secret', 'abcdefghijklmnop', 'postgres', 'password', '5432');

        $this->assertSame('https://abcdefghijklmnop.storage.eu-central-1.nhost.run', $source->storageURL);
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: string} $coordinates subdomain, region, database, port
     */
    #[DataProvider('hostileCoordinates')]
    public function testRefusesCoordinatesThatLeaveNhostRun(array $coordinates): void
    {
        [$subdomain, $region, $database, $port] = $coordinates;

        $this->expectException(\InvalidArgumentException::class);

        new NHost($subdomain, $region, 'secret', $database, 'postgres', 'password', $port);
    }

    /**
     * @return \Iterator<string, array{0: array{0: string, 1: string, 2: string, 3: string}}>
     */
    public static function hostileCoordinates(): \Iterator
    {
        yield 'conninfo keyword in subdomain' => [['x hostaddr=127.0.0.1', 'eu-central-1', 'postgres', '5432']];
        yield 'separator in subdomain' => [['x;port=6379', 'eu-central-1', 'postgres', '5432']];
        yield 'dotted subdomain' => [['internal.example.com#', 'eu-central-1', 'postgres', '5432']];
        yield 'empty region' => [['abc', '', 'postgres', '5432']];
        yield 'conninfo keyword in database' => [['abc', 'eu-central-1', 'postgres hostaddr=10.0.0.5', '5432']];
        yield 'quoted database' => [['abc', 'eu-central-1', "postgres' host='10.0.0.5", '5432']];
        yield 'empty database' => [['abc', 'eu-central-1', '', '5432']];
        yield 'port with keyword' => [['abc', 'eu-central-1', 'postgres', '5432 host=10.0.0.5']];
        yield 'port out of range' => [['abc', 'eu-central-1', 'postgres', '70000']];
        yield 'leading hyphen' => [['-abc', 'eu-central-1', 'postgres', '5432']];
    }
}
