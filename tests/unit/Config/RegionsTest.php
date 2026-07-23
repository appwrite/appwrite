<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Appwrite\Config\Regions;
use PHPUnit\Framework\TestCase;

final class RegionsTest extends TestCase
{
    public function testParseAppwriteRegionsCatalog(): void
    {
        $catalog = Regions::parse(\json_encode([
            'default' => [
                '$id' => 'default',
                'name' => 'Default',
                'disabled' => true,
                'default' => false,
            ],
            'fra' => [
                '$id' => 'fra',
                'name' => 'Frankfurt',
                'disabled' => false,
                'default' => true,
            ],
            'nyc' => [
                '$id' => 'nyc',
                'name' => 'New York',
                'disabled' => false,
                'default' => false,
            ],
        ]));

        $this->assertEquals(['default', 'fra', 'nyc'], \array_keys($catalog));
        $this->assertTrue($catalog['fra']['default']);
        $this->assertTrue($catalog['default']['disabled']);
        $this->assertEquals('New York', $catalog['nyc']['name']);
    }

    public function testParseCustomRegionsCatalog(): void
    {
        $catalog = Regions::parse(\json_encode([
            'default' => [
                '$id' => 'default',
                'name' => 'Default',
                'disabled' => true,
                'default' => false,
            ],
            'france' => [
                '$id' => 'france',
                'name' => 'France',
                'disabled' => false,
                'default' => true,
            ],
            'japan' => [
                '$id' => 'japan',
                'name' => 'Japan',
                'disabled' => false,
                'default' => false,
            ],
        ]));

        $this->assertEquals(['default', 'france', 'japan'], \array_keys($catalog));
        $this->assertTrue($catalog['france']['default']);
        $this->assertEquals('Japan', $catalog['japan']['name']);
    }

    public function testParseListCatalog(): void
    {
        $catalog = Regions::parse(\json_encode([
            [
                '$id' => 'sfo',
                'name' => 'San Francisco',
                'disabled' => false,
                'default' => true,
            ],
        ]));

        $this->assertArrayHasKey('sfo', $catalog);
        $this->assertTrue($catalog['sfo']['default']);
    }

    public function testParseRejectsInvalidJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Regions::parse('not-json');
    }

    public function testParseRejectsInvalidRegionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Regions::parse(\json_encode([
            'FRA' => [
                '$id' => 'FRA',
                'name' => 'Frankfurt',
                'default' => true,
            ],
        ]));
    }

    public function testParseRejectsMultipleDefaults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Regions::parse(\json_encode([
            'fra' => [
                '$id' => 'fra',
                'name' => 'Frankfurt',
                'default' => true,
            ],
            'nyc' => [
                '$id' => 'nyc',
                'name' => 'New York',
                'default' => true,
            ],
        ]));
    }

    public function testPoolKeyMatchesAppwriteRegions(): void
    {
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_fra_main', 'fra'));
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_nyc_main', 'nyc'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_frankfurt_main', 'fra'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_nyc_main', 'fra'));
    }

    public function testPoolKeyMatchesCustomRegions(): void
    {
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_france_main', 'france'));
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_japan_main', 'japan'));
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_eu-west-1_main', 'eu-west-1'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_franceville_main', 'france'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_australia_main', 'us'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_france_main', 'fra'));
    }

    public function testFilterPoolKeysForAppwriteAndCustomRegions(): void
    {
        $keys = [
            'database_db_fra_main',
            'database_db_nyc_main',
            'database_db_france_main',
            'database_db_japan_main',
            'database_db_frankfurt_main',
        ];

        $this->assertEquals(
            ['database_db_fra_main'],
            Regions::filterPoolKeysForRegion($keys, 'fra')
        );
        $this->assertEquals(
            ['database_db_france_main'],
            Regions::filterPoolKeysForRegion($keys, 'france')
        );
    }
}
