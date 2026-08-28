<?php

namespace Tests\Unit\Migration\Infrastructure;

use Appwrite\Migration\Infrastructure\Migration;
use Appwrite\Migration\Infrastructure\Version\V1;
use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $versions = [];

    protected function setUp(): void
    {
        $this->versions = Migration::$versions;
    }

    protected function tearDown(): void
    {
        Migration::$versions = $this->versions;
    }

    public function testRunsTheReleasesAnUpgradeCrosses(): void
    {
        $this->assertSame([V1::class], \array_map('get_class', Migration::between('1.9.6', '2.0.0')));
    }

    public function testSkipsReleasesTheInstallationIsAlreadyOn(): void
    {
        $this->assertSame([], Migration::between('2.0.0', '2.1.0'));
        $this->assertSame([], Migration::between('2.0.0', '2.0.0'));
    }

    public function testSkipsReleasesTheUpgradeDoesNotReach(): void
    {
        $this->assertSame([], Migration::between('1.9.0', '1.9.6'));
    }

    /**
     * A pre-release carries the infrastructure of the release it leads to, so upgrading
     * onto 2.0.0-rc.1 has to run the same migrations as upgrading onto 2.0.0.
     */
    public function testTreatsAPreReleaseAsItsRelease(): void
    {
        $this->assertSame([V1::class], \array_map('get_class', Migration::between('1.9.6', '2.0.0-rc.1')));
        $this->assertSame([], Migration::between('2.0.0-rc.1', '2.0.0'));
    }

    /**
     * Development installs are pinned to a tag that is not a version at all, and are
     * newer than every release.
     */
    public function testTreatsAnUnversionedTagAsTheNewestRelease(): void
    {
        $this->assertSame([V1::class], \array_map('get_class', Migration::between('1.9.6', 'latest')));
        $this->assertSame([], Migration::between('latest', '2.0.0'));
    }

    public function testRunsMigrationsOldestFirst(): void
    {
        Migration::$versions = [
            '3.0.0' => 'V1',
            '2.0.0' => 'V1',
        ];

        $this->assertCount(2, Migration::between('1.9.6', '3.0.0'));
    }

    public function testSkipsAVersionWithNoMigrationClass(): void
    {
        Migration::$versions = ['2.0.0' => 'DoesNotExist'];

        $this->assertSame([], Migration::between('1.9.6', '2.0.0'));
    }
}
