<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use Appwrite\Locking\Lock;
use Appwrite\Onboarding\Stages;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Filter;
use Utopia\Database\Validator\Authorization;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class StagesTest extends TestCase
{
    private const string PROJECT = 'project-1';

    private Database $database;

    private Authorization $authorization;

    private Stages $stages;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();

        $this->database = new Database(
            new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])),
            new Cache(new MemoryCache()),
        );
        $this->database
            ->setDatabase('appwrite')
            ->setNamespace('platform')
            ->setAuthorization($this->authorization);

        $this->authorization->skip(function (): void {
            $this->database->create();
            $this->database->createCollection(Collection::create(
                id: 'projects',
                attributes: [Attribute::string(key: 'onboarding', size: 65536, default: [], filters: [Filter::Json])],
            ));
            $this->database->createDocument('projects', new Document([
                '$id' => self::PROJECT,
                '$permissions' => [],
            ]));
        });

        $lock = new Lock(
            static fn (string $key, int $ttl, \Closure $callback): mixed => $callback(new BrieflyHeldLock()),
            new NoTelemetry(),
            $this->project(),
        );

        $this->stages = new Stages($this->database, $this->authorization, $lock);
    }

    public function testCompleteRecordsTheStage(): void
    {
        $this->stages->complete($this->project(), ['cli.install'], ACTOR_TYPE_GUEST);

        $stage = $this->stored()['cli.install'];
        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $stage['status']);
        $this->assertSame(ACTOR_TYPE_GUEST, $stage['actorType']);
        $this->assertNotEmpty($stage['at']);
    }

    public function testCompleteKeepsStagesRecordedAfterTheCallerReadTheProject(): void
    {
        $project = $this->project();

        $this->stages->complete($project, ['cli.install'], ACTOR_TYPE_GUEST);
        $this->stages->complete($project, ['mcp.install'], ACTOR_TYPE_GUEST);

        $stored = $this->stored();
        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $stored['cli.install']['status'] ?? null, 'A request holding an older copy of the project must not erase a stage another request recorded');
        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $stored['mcp.install']['status'] ?? null);
    }

    public function testCompleteWaitsForTheLockInsteadOfDroppingTheStage(): void
    {
        $this->stages->complete($this->project(), ['teams.create'], ACTOR_TYPE_USER);

        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $this->stored()['teams.create']['status'] ?? null, 'A stage must be recorded when another request holds the onboarding lock for a moment');
    }

    public function testCompleteKeepsTheFirstCompletion(): void
    {
        $this->stages->complete($this->project(), ['cli.install'], ACTOR_TYPE_GUEST);
        $first = $this->stored()['cli.install'];

        $this->stages->complete($this->project(), ['cli.install'], ACTOR_TYPE_ADMIN);

        $this->assertSame($first, $this->stored()['cli.install']);
    }

    public function testCompleteUpgradesASkippedStage(): void
    {
        $this->stages->skip($this->project(), 'tablesDB.create', ACTOR_TYPE_USER);

        $this->stages->complete($this->project(), ['tablesDB.create'], ACTOR_TYPE_KEY_PROJECT);

        $stage = $this->stored()['tablesDB.create'];
        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $stage['status']);
        $this->assertSame(ACTOR_TYPE_KEY_PROJECT, $stage['actorType']);
    }

    public function testSkipMarksAPendingStageAndReturnsIt(): void
    {
        $row = $this->stages->skip($this->project(), 'storage.createBucket', ACTOR_TYPE_USER);

        $this->assertSame(ONBOARDING_STATUS_SKIPPED, $row['status'] ?? null);
        $this->assertSame(ACTOR_TYPE_USER, $row['actorType'] ?? null);
        $this->assertSame($row, $this->stored()['storage.createBucket']);
    }

    public function testSkipLeavesACompletedStageCompleted(): void
    {
        $project = $this->project();
        $this->stages->complete($project, ['functions.create'], ACTOR_TYPE_KEY_PROJECT);

        $row = $this->stages->skip($project, 'functions.create', ACTOR_TYPE_USER);

        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $row['status'] ?? null, 'Skipping reads the stored stage, not the caller\'s older copy');
        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $this->stored()['functions.create']['status']);
    }

    public function testSkipKeepsStagesRecordedAfterTheCallerReadTheProject(): void
    {
        $project = $this->project();
        $this->stages->complete($project, ['cli.install'], ACTOR_TYPE_GUEST);

        $this->stages->skip($project, 'tablesDB.create', ACTOR_TYPE_USER);

        $this->assertSame(ONBOARDING_STATUS_COMPLETED, $this->stored()['cli.install']['status'] ?? null);
        $this->assertSame(ONBOARDING_STATUS_SKIPPED, $this->stored()['tablesDB.create']['status'] ?? null);
    }

    private function project(): Document
    {
        return $this->authorization->skip(fn () => $this->database->getDocument('projects', self::PROJECT));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function stored(): array
    {
        $stages = $this->project()->getAttribute('onboarding', []);
        $this->assertIsArray($stages);

        return $stages;
    }
}
