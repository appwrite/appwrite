<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Projects\Http;

use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Modules\Organization\Http\Projects\Create as OrganizationCreate;
use Appwrite\Platform\Modules\Projects\Http\Projects\Create as ProjectsCreate;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

require_once __DIR__ . '/../../../../../../app/init.php';

/**
 * Project create must seed the default encode ladder on both dedicated and
 * shared-tables hosts. The dedicated path is also covered by e2e
 * `testListSeededProfiles`; shared tables are not on that host, so this test
 * drives both Create actions against a Memory adapter.
 */
final class CreateVideoProfilesTest extends TestCase
{
    private const string HOST_DEDICATED = 'projects-dedicated';

    private const string HOST_SHARED = 'projects-shared';

    private const string SHARED_NAMESPACE = 'shared_ns';

    private const array PROFILE_NAMES = ['360p', '480p', '576p', '720p', '1080p', '2160p'];

    /** @var array<string, mixed> */
    private array $params;

    /** @var array<string, string|false> */
    private array $variables = [];

    protected function setUp(): void
    {
        $this->params = Config::$params;

        foreach ([
            '_APP_DATABASE_SHARED_TABLES',
            '_APP_DATABASE_SHARED_NAMESPACE',
            '_APP_DATABASE_OVERRIDE',
            '_APP_PROJECT_REGIONS',
            '_APP_DATABASE_KEYS',
        ] as $variable) {
            $this->variables[$variable] = \getenv($variable);
            \putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        Config::$params = $this->params;

        foreach ($this->variables as $variable => $value) {
            \putenv($value === false ? $variable : $variable . '=' . $value);
        }
    }

    public function testProjectsCreateSeedsDedicatedProfiles(): void
    {
        [$authorization, $cache, $dbForPlatform, $projectMemory, $pools, $team] = $this->arrangeDedicated();

        $this->createViaProjects($authorization, $cache, $dbForPlatform, $pools, $team, 'proj-dedicated-a');

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', 'proj-dedicated-a'));
        $profiles = $this->findProfiles(
            $authorization,
            $cache,
            $projectMemory,
            shared: false,
            tenant: null,
            namespace: '_' . $project->getSequence(),
        );

        $this->assertProfileLadder($profiles);
    }

    public function testOrganizationCreateSeedsDedicatedProfiles(): void
    {
        [$authorization, $cache, $dbForPlatform, $projectMemory, $pools, $team] = $this->arrangeDedicated();

        $this->createViaOrganization($authorization, $cache, $dbForPlatform, $pools, $team, 'org-dedicated-a');

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', 'org-dedicated-a'));
        $profiles = $this->findProfiles(
            $authorization,
            $cache,
            $projectMemory,
            shared: false,
            tenant: null,
            namespace: '_' . $project->getSequence(),
        );

        $this->assertProfileLadder($profiles);
    }

    public function testProjectsCreateSeedsSharedTableProfilesPerTenant(): void
    {
        [$authorization, $cache, $dbForPlatform, $projectMemory, $pools, $team] = $this->arrangeShared();

        $this->createViaProjects($authorization, $cache, $dbForPlatform, $pools, $team, 'proj-shared-a');
        $this->createViaProjects($authorization, $cache, $dbForPlatform, $pools, $team, 'proj-shared-b');

        $projectA = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', 'proj-shared-a'));
        $projectB = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', 'proj-shared-b'));

        $profilesA = $this->findProfiles(
            $authorization,
            $cache,
            $projectMemory,
            shared: true,
            tenant: $projectA->getSequence(),
            namespace: self::SHARED_NAMESPACE,
        );
        $profilesB = $this->findProfiles(
            $authorization,
            $cache,
            $projectMemory,
            shared: true,
            tenant: $projectB->getSequence(),
            namespace: self::SHARED_NAMESPACE,
        );

        $this->assertProfileLadder($profilesA);
        $this->assertProfileLadder($profilesB);

        $idsA = \array_map(fn (Document $profile) => $profile->getId(), $profilesA);
        $idsB = \array_map(fn (Document $profile) => $profile->getId(), $profilesB);
        $this->assertSame([], \array_intersect($idsA, $idsB));
    }

    public function testOrganizationCreateSeedsSharedTableProfilesPerTenant(): void
    {
        [$authorization, $cache, $dbForPlatform, $projectMemory, $pools, $team] = $this->arrangeShared();

        $this->createViaOrganization($authorization, $cache, $dbForPlatform, $pools, $team, 'org-shared-a');
        $this->createViaOrganization($authorization, $cache, $dbForPlatform, $pools, $team, 'org-shared-b');

        $projectA = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', 'org-shared-a'));
        $projectB = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', 'org-shared-b'));

        $profilesA = $this->findProfiles(
            $authorization,
            $cache,
            $projectMemory,
            shared: true,
            tenant: $projectA->getSequence(),
            namespace: self::SHARED_NAMESPACE,
        );
        $profilesB = $this->findProfiles(
            $authorization,
            $cache,
            $projectMemory,
            shared: true,
            tenant: $projectB->getSequence(),
            namespace: self::SHARED_NAMESPACE,
        );

        $this->assertProfileLadder($profilesA);
        $this->assertProfileLadder($profilesB);

        $idsA = \array_map(fn (Document $profile) => $profile->getId(), $profilesA);
        $idsB = \array_map(fn (Document $profile) => $profile->getId(), $profilesB);
        $this->assertSame([], \array_intersect($idsA, $idsB));
    }

    /**
     * @return array{0: Authorization, 1: Cache, 2: Database, 3: Memory, 4: Group, 5: Document}
     */
    private function arrangeDedicated(): array
    {
        Config::setParam('pools-database', [self::HOST_DEDICATED]);
        \putenv('_APP_DATABASE_OVERRIDE=' . self::HOST_DEDICATED);
        \putenv('_APP_DATABASE_SHARED_TABLES=');

        return $this->arrange(self::HOST_DEDICATED, shared: false);
    }

    /**
     * @return array{0: Authorization, 1: Cache, 2: Database, 3: Memory, 4: Group, 5: Document}
     */
    private function arrangeShared(): array
    {
        Config::setParam('pools-database', [self::HOST_SHARED]);
        \putenv('_APP_DATABASE_OVERRIDE=' . self::HOST_SHARED);
        \putenv('_APP_DATABASE_SHARED_TABLES=' . self::HOST_SHARED);
        \putenv('_APP_DATABASE_SHARED_NAMESPACE=' . self::SHARED_NAMESPACE);

        return $this->arrange(self::HOST_SHARED, shared: true);
    }

    /**
     * @return array{0: Authorization, 1: Cache, 2: Database, 3: Memory, 4: Group, 5: Document}
     */
    private function arrange(string $host, bool $shared): array
    {
        $authorization = new Authorization();
        $authorization->disable();
        $cache = new Cache(new NoCache());

        $platformMemory = new Memory();
        $dbForPlatform = new Database($platformMemory, $cache);
        $dbForPlatform
            ->setAuthorization($authorization)
            ->setDatabase(APP_DATABASE)
            ->setNamespace('_console');
        $dbForPlatform->create();
        // Project/team decode runs platform subquery filters against these.
        foreach (['projects', 'teams', 'keys', 'platforms', 'webhooks'] as $collectionId) {
            $this->createCollection($dbForPlatform, 'console', $collectionId);
        }

        $team = $dbForPlatform->createDocument('teams', new Document([
            '$id' => 'team-' . \uniqid(),
            'name' => 'Seed Profiles Team',
            'total' => 1,
            'search' => 'seed',
        ]));

        $projectMemory = new Memory();
        $pools = new Group();
        $pools->add(new Pool(new Stack(), $host, 1, static fn () => $projectMemory, 1.0));

        if ($shared) {
            $sharedDb = new Database($projectMemory, $cache);
            $sharedDb
                ->setAuthorization($authorization)
                ->setDatabase(APP_DATABASE)
                ->setSharedTables(true)
                ->setTenant(null)
                ->setNamespace(self::SHARED_NAMESPACE);
            $sharedDb->create();
            $this->createCollection($sharedDb, 'projects', 'videos_profiles');
        }

        return [$authorization, $cache, $dbForPlatform, $projectMemory, $pools, $team];
    }

    private function createViaProjects(
        Authorization $authorization,
        Cache $cache,
        Database $dbForPlatform,
        Group $pools,
        Document $team,
        string $projectId,
    ): void {
        $response = $this->createMock(Response::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->expects($this->once())->method('dynamic');

        (new ProjectsCreate())->action(
            $projectId,
            'Seed Profiles Project',
            $team->getId(),
            'default',
            $this->createMock(Request::class),
            $response,
            $dbForPlatform,
            $cache,
            $pools,
            new Hooks(),
            $authorization,
        );
    }

    private function createViaOrganization(
        Authorization $authorization,
        Cache $cache,
        Database $dbForPlatform,
        Group $pools,
        Document $team,
        string $projectId,
    ): void {
        $response = $this->createMock(Response::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->expects($this->once())->method('dynamic');

        // Organization Create's constructor builds an SDK Method that needs
        // Swoole\Http\Response; action() does not use constructor state.
        $create = (new \ReflectionClass(OrganizationCreate::class))->newInstanceWithoutConstructor();
        $create->action(
            $projectId,
            'Seed Profiles Project',
            'default',
            $response,
            $dbForPlatform,
            $cache,
            $pools,
            new Hooks(),
            $team,
            $authorization,
        );
    }

    private function createCollection(Database $database, string $scope, string $collectionId): void
    {
        /** @var array $collection */
        $collection = Config::getParam('collections', [])[$scope][$collectionId];
        $attributes = \array_map(fn ($attribute) => new Document($attribute), $collection['attributes']);
        $indexes = \array_map(fn (array $index) => new Document($index), $collection['indexes']);
        $database->createCollection($collectionId, $attributes, $indexes);
    }

    /**
     * @return list<Document>
     */
    private function findProfiles(
        Authorization $authorization,
        Cache $cache,
        Memory $memory,
        bool $shared,
        int|string|null $tenant,
        string $namespace,
    ): array {
        // Document::getTenant() casts numeric values to int before Memory
        // stores `_tenant`. fusedFilter then compares with !== against the
        // adapter tenant, so a string sequence like "1" misses int rows.
        if (\is_numeric($tenant)) {
            $tenant = (int) $tenant;
        }

        $database = new Database($memory, $cache);
        $database
            ->setAuthorization($authorization)
            ->setDatabase(APP_DATABASE)
            ->setSharedTables($shared)
            ->setTenant($tenant)
            ->setNamespace($namespace);

        return $authorization->skip(fn () => $database->find('videos_profiles', [
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
    }
}
