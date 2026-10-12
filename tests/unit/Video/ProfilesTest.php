<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use Appwrite\Video\Profiles;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

final class ProfilesTest extends TestCase
{
    private const array PROFILE_NAMES = ['360p', '480p', '576p', '720p', '1080p', '2160p'];

    /** @var array<string, mixed> */
    private array $params;

    protected function setUp(): void
    {
        $this->params = Config::$params;
        Config::setParam('videos-profiles', require \dirname(__DIR__, 3) . '/app/config/videos-profiles.php');
    }

    protected function tearDown(): void
    {
        Config::$params = $this->params;
    }

    public function testSeedsDedicatedLadder(): void
    {
        $database = $this->database(shared: false, namespace: '_1');

        Profiles::seed($database);
        Profiles::seed($database);

        $this->assertProfileLadder($this->findProfiles($database));
    }

    public function testSeedsSharedTablesPerTenant(): void
    {
        $database = $this->database(shared: true, namespace: 'shared_ns');

        $database->setTenant(1);
        Profiles::seed($database);
        $database->setTenant(2);
        Profiles::seed($database);
        Profiles::seed($database);

        $database->setTenant(1);
        $profilesA = $this->findProfiles($database);
        $database->setTenant(2);
        $profilesB = $this->findProfiles($database);

        $this->assertProfileLadder($profilesA);
        $this->assertProfileLadder($profilesB);

        $idsA = \array_map(fn (Document $profile) => $profile->getId(), $profilesA);
        $idsB = \array_map(fn (Document $profile) => $profile->getId(), $profilesB);
        $this->assertSame([], \array_intersect($idsA, $idsB));
    }

    private function database(bool $shared, string $namespace): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('appwrite')
            ->setSharedTables($shared)
            ->setTenant(null)
            ->setNamespace($namespace);
        $database->create();

        /** @var array $collection */
        $collection = (require \dirname(__DIR__, 3) . '/app/config/collections/projects.php')['videos_profiles'];
        $attributes = \array_map(fn ($attribute) => new Document($attribute), $collection['attributes']);
        $indexes = \array_map(fn (array $index) => new Document($index), $collection['indexes']);
        $database->createCollection('videos_profiles', $attributes, $indexes);

        return $database;
    }

    /**
     * @return list<Document>
     */
    private function findProfiles(Database $database): array
    {
        return $database->getAuthorization()->skip(fn () => $database->find('videos_profiles', [
            Query::limit(100),
        ]));
    }

    /**
     * @param list<Document> $profiles
     */
    private function assertProfileLadder(array $profiles): void
    {
        $this->assertCount(6, $profiles);
        $names = \array_map(fn (Document $profile) => $profile->getAttribute('name'), $profiles);
        \sort($names);
        $expected = self::PROFILE_NAMES;
        \sort($expected);
        $this->assertSame($expected, $names);

        foreach ($profiles as $profile) {
            $this->assertSame('h264', $profile->getAttribute('codec'));
            $this->assertSame($profile->getAttribute('name'), $profile->getAttribute('search'));
        }
    }
}
