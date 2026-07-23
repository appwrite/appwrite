<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Appwrite\Config\Regions;
use PHPUnit\Framework\TestCase;

final class RegionsTest extends TestCase
{
    public function testParseCatalog(): void
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
        $this->assertTrue($catalog['default']['disabled']);
        $this->assertEquals('Japan', $catalog['japan']['name']);
    }

    public function testParseListCatalog(): void
    {
        $catalog = Regions::parse(\json_encode([
            [
                '$id' => 'eu-west-1',
                'name' => 'EU West',
                'disabled' => false,
                'default' => true,
            ],
        ]));

        $this->assertArrayHasKey('eu-west-1', $catalog);
        $this->assertTrue($catalog['eu-west-1']['default']);
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
            'France' => [
                '$id' => 'France',
                'name' => 'France',
                'default' => true,
            ],
        ]));
    }

    public function testParseRejectsMultipleDefaults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Regions::parse(\json_encode([
            'france' => [
                '$id' => 'france',
                'name' => 'France',
                'default' => true,
            ],
            'japan' => [
                '$id' => 'japan',
                'name' => 'Japan',
                'default' => true,
            ],
        ]));
    }

    public function testPoolKeyMatchesRegion(): void
    {
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_france_main', 'france'));
        $this->assertTrue(Regions::poolKeyMatchesRegion('database_db_eu-west-1_main', 'eu-west-1'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_franceville_main', 'france'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_australia_main', 'us'));
        $this->assertFalse(Regions::poolKeyMatchesRegion('database_db_france_main', 'fra'));
    }

    public function testFilterPoolKeysForRegion(): void
    {
        $keys = [
            'database_db_france_main',
            'database_db_japan_main',
            'database_db_franceville_main',
        ];

        $this->assertEquals(
            ['database_db_france_main'],
            Regions::filterPoolKeysForRegion($keys, 'france')
        );
    }
}
