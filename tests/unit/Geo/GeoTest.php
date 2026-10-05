<?php

declare(strict_types=1);

namespace Tests\Unit\Geo;

use Appwrite\Geo\Client;
use Appwrite\Geo\Geo;
use PHPUnit\Framework\TestCase;
use Swoole\Table;
use Utopia\Config\Config;
use Utopia\Locale\Locale;

final class GeoTest extends TestCase
{
    public function testCachePreservesPremiumGeoAttributes(): void
    {
        Config::setParam('locale-eu', []);
        Config::setParam('locale-currencies', []);
        Locale::setLanguageFromArray('en', []);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('lookup')
            ->with('203.0.113.10')
            ->willReturn([
                'countryCode' => 'DE',
                'continentCode' => 'EU',
                'city' => ['en' => 'Frankfurt'],
                'subdivisions' => [
                    ['iso_code' => 'HE', 'names' => ['en' => 'Hesse']],
                ],
                'isp' => 'Example ISP',
            ]);

        $cache = new Table(16);
        $cache->column('value', Table::TYPE_STRING, Geo::CACHE_VALUE_SIZE);
        $cache->create();

        $geo = new Geo($client, new Locale('en'), $cache);
        $geo->get('203.0.113.10');
        $cached = $geo->get('203.0.113.10');

        $this->assertSame('Frankfurt', $cached->getAttribute('city'));
        $this->assertSame('Hesse', $cached->getAttribute('subdivisions')[0]['names']['en']);
        $this->assertSame('Example ISP', $cached->getAttribute('isp'));
    }
}
