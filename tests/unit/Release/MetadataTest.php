<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;

/**
 * Release metadata is spread across four files that have to agree, and nothing
 * used to check that they do -- a published version could disagree with what the
 * repo said it was. `release X.Y.Z` writes all four; this fails when they drift.
 *
 * Follows the repo-consistency precedent of Tests\Unit\Migration\MigrationVersionsTest.
 */
final class MetadataTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    protected function setUp(): void
    {
        require_once self::ROOT . '/app/init.php';
    }

    public function testStableVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-(rc\.\d+|RC\d+))?$/',
            APP_VERSION_STABLE,
            'APP_VERSION_STABLE must be an X.Y.Z version, optionally a release candidate.'
        );
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function readmes(): \Iterator
    {
        yield 'README.md' => ['README.md'];
        yield 'README-CN.md' => ['README-CN.md'];
    }

    /**
     * Every install snippet pins the released version. A README that still points
     * at the previous version sends new self-hosters to an older image.
     *
     * @dataProvider readmes
     */
    public function testReadmePinsStableVersion(string $readme): void
    {
        $contents = \file_get_contents(self::ROOT . '/' . $readme);
        $this->assertNotFalse($contents, "{$readme} is unreadable.");

        \preg_match_all('#appwrite/appwrite:(\S+)#', $contents, $matches);

        $this->assertNotEmpty($matches[1], "{$readme} has no appwrite/appwrite install snippet to check.");

        foreach (\array_unique($matches[1]) as $pinned) {
            $this->assertSame(
                APP_VERSION_STABLE,
                $pinned,
                "{$readme} pins appwrite/appwrite:{$pinned} but APP_VERSION_STABLE is " . APP_VERSION_STABLE . '.'
            );
        }
    }

    /**
     * A version with no migration mapping cannot be upgraded to, so the release
     * would install and then fail for everyone already running Appwrite.
     */
    public function testStableVersionHasAMigration(): void
    {
        if (\str_contains(APP_VERSION_STABLE, 'RC') || \str_contains(APP_VERSION_STABLE, '-rc.')) {
            $this->markTestSkipped('Release candidates are mapped when the final version is cut.');
        }

        $this->assertArrayHasKey(
            APP_VERSION_STABLE,
            Migration::$versions,
            'APP_VERSION_STABLE has no entry in Migration::$versions.'
        );
    }

    /**
     * The last mapped version is the one being released. An entry newer than
     * APP_VERSION_STABLE means a version was mapped but never marked stable.
     */
    public function testNoMigrationMappingIsAheadOfStable(): void
    {
        if (\str_contains(APP_VERSION_STABLE, 'RC') || \str_contains(APP_VERSION_STABLE, '-rc.')) {
            $this->markTestSkipped('Release candidates are mapped when the final version is cut.');
        }

        foreach (\array_keys(Migration::$versions) as $mapped) {
            $this->assertLessThanOrEqual(
                0,
                \version_compare((string) $mapped, APP_VERSION_STABLE),
                "Migration::\$versions maps {$mapped}, which is newer than APP_VERSION_STABLE " . APP_VERSION_STABLE . '.'
            );
        }
    }

    /**
     * The console ships as its own image, so self-hosted installs only get a
     * console fix when this pin moves. An unpinned or floating tag would make an
     * install unreproducible.
     */
    public function testConsoleImageIsPinnedToAVersion(): void
    {
        $compose = \file_get_contents(self::ROOT . '/docker-compose.yml');
        $this->assertNotFalse($compose, 'docker-compose.yml is unreadable.');

        $this->assertSame(
            1,
            \preg_match('#image: appwrite/new:(\S+)#', $compose, $matches),
            'docker-compose.yml does not pin an appwrite/new console image.'
        );

        // A build suffix is fine (1.1.78-self-hosted); a floating tag is not.
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/',
            $matches[1],
            "The console is pinned to '{$matches[1]}', which is not an exact version."
        );
    }
}
