<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\ScheduleSource;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Trigger\Cron;

final class ScheduleSourceTest extends TestCase
{
    private Database $dbForPlatform;

    protected function setUp(): void
    {
        $this->dbForPlatform = new Database(new Memory(), new Cache(new NoCache()));
        $this->dbForPlatform->setDatabase('test');
        $this->dbForPlatform->create();
        $this->dbForPlatform->createCollection('schedules', [
            new Document(['$id' => 'region', 'type' => Database::VAR_STRING, 'size' => 64, 'required' => false]),
            new Document(['$id' => 'resourceType', 'type' => Database::VAR_STRING, 'size' => 64, 'required' => false]),
            new Document(['$id' => 'resourceId', 'type' => Database::VAR_STRING, 'size' => 64, 'required' => false]),
            new Document(['$id' => 'resourceUpdatedAt', 'type' => Database::VAR_DATETIME, 'size' => 0, 'required' => false, 'filters' => ['datetime']]),
            new Document(['$id' => 'projectId', 'type' => Database::VAR_STRING, 'size' => 64, 'required' => false]),
            new Document(['$id' => 'schedule', 'type' => Database::VAR_STRING, 'size' => 64, 'required' => false]),
            new Document(['$id' => 'active', 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'required' => false]),
            new Document(['$id' => 'data', 'type' => Database::VAR_STRING, 'size' => 1024, 'required' => false, 'array' => true]),
        ]);
    }

    /**
     * A dispatch that sleeps holds a schedule the source may since have
     * stopped reporting. The live view is what tells it so.
     */
    public function testADisabledScheduleStopsBeingLive(): void
    {
        $source = $this->source();
        $updatedAt = DateTime::now();
        $row = $this->schedule('fn-a', $updatedAt);
        $version = $this->version($row);

        $this->assertCount(1, [...$source->snapshot()]);
        $this->assertTrue($source->isLive((string) $row->getSequence(), $version));

        // What the console does when a user turns a schedule off.
        $disabledAt = DateTime::addSeconds(new \DateTime(), 1);
        $this->dbForPlatform->updateDocument('schedules', $row->getId(), new Document([
            'active' => false,
            'resourceUpdatedAt' => $disabledAt,
        ]));

        $this->assertCount(1, [...$source->since(new \DateTimeImmutable('-1 hour'))]);
        $this->assertFalse($source->isLive((string) $row->getSequence(), $version));
        $this->assertFalse($source->isLive((string) $row->getSequence(), $this->version($row)));
    }

    /**
     * An edited definition covers its own occurrences from its new
     * activeFrom, so the run captured against the superseded one must not
     * also go out.
     */
    public function testAnEditedScheduleRetiresTheVersionInFlight(): void
    {
        $source = $this->source();
        $updatedAt = DateTime::now();
        $row = $this->schedule('fn-b', $updatedAt);
        $version = $this->version($row);

        [...$source->snapshot()];

        $editedAt = DateTime::addSeconds(new \DateTime(), 1);
        $this->dbForPlatform->updateDocument('schedules', $row->getId(), new Document([
            'schedule' => '*/5 * * * *',
            'resourceUpdatedAt' => $editedAt,
        ]));

        $edited = $this->version($row);
        [...$source->since(new \DateTimeImmutable('-1 hour'))];

        $this->assertNotSame($version, $edited);
        $this->assertFalse($source->isLive((string) $row->getSequence(), $version));
        $this->assertTrue($source->isLive((string) $row->getSequence(), $edited));
    }

    /**
     * A hard delete is reported by no change feed, so only the snapshot can
     * converge it — which is why the live view is rebuilt from one.
     */
    public function testADeletedScheduleStopsBeingLiveOnTheNextSnapshot(): void
    {
        $source = $this->source();
        $updatedAt = DateTime::now();
        $row = $this->schedule('fn-c', $updatedAt);
        $version = $this->version($row);

        [...$source->snapshot()];
        $this->dbForPlatform->deleteDocument('schedules', $row->getId());

        [...$source->since(new \DateTimeImmutable('-1 hour'))];
        $this->assertTrue($source->isLive((string) $row->getSequence(), $version));

        [...$source->snapshot()];
        $this->assertFalse($source->isLive((string) $row->getSequence(), $version));
    }

    private function source(): ScheduleSource
    {
        return new ScheduleSource(
            dbForPlatform: $this->dbForPlatform,
            getProjectDB: fn (Document $project): Database => $this->dbForPlatform,
            isResourceBlocked: fn (): bool => false,
            resourceType: 'function',
            collectionId: 'functions',
            resource: fn (Database $projectDB, array $schedule): Document => new Document(['$id' => $schedule['resourceId']]),
            entry: fn (array $schedule): Entry => new Entry(new Cron((string) $schedule['schedule']), $schedule),
            recency: 180,
        );
    }

    /**
     * The version a deferred dispatch carries: whatever the stored row
     * reports, which is what `make()` puts in the payload.
     */
    private function version(Document $row): string
    {
        return (string) $this->dbForPlatform->getDocument('schedules', $row->getId())->getAttribute('resourceUpdatedAt', '');
    }

    private function schedule(string $resourceId, string $updatedAt): Document
    {
        return $this->dbForPlatform->createDocument('schedules', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'region' => 'default',
            'resourceType' => 'function',
            'resourceId' => $resourceId,
            'resourceUpdatedAt' => $updatedAt,
            'projectId' => 'project',
            'schedule' => '* * * * *',
            'active' => true,
            'data' => [],
        ]));
    }
}
