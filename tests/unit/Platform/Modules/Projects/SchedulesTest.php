<?php

namespace Tests\Unit\Platform\Modules\Projects;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\Projects\Http\Schedules\Get;
use Appwrite\Platform\Modules\Projects\Http\Schedules\XList;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../app/init.php';

class CapturingSchedulesResponse extends Response
{
    public Document $document;
    public string $model = '';

    public function __construct()
    {
    }

    public function dynamic(Document $document, string $model): void
    {
        $this->document = $document;
        $this->model = $model;
    }
}

final class SchedulesTest extends TestCase
{
    private Authorization $authorization;
    private Database $database;
    private Document $project;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization($this->authorization)
            ->setDatabase('scheduleTests')
            ->setNamespace('schedules_' . \uniqid());

        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $this->database->create();
        $this->database->createCollection('projects', [], [], $permissions, false);
        $this->database->createCollection('schedules', [], [], $permissions, false);
        $this->database->createAttribute('schedules', 'projectId', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('schedules', 'projectInternalId', Database::VAR_ID, 0, false);

        $this->project = $this->database->createDocument('projects', new Document([
            '$id' => 'project-a',
        ]));
    }

    protected function tearDown(): void
    {
        $this->authorization->cleanRoles();
        $this->authorization->addRole(Role::any()->toString());
    }

    public function testGetAllowsLegacyScheduleWithoutProjectInternalId(): void
    {
        $this->createSchedule('legacy');

        $response = new CapturingSchedulesResponse();

        (new Get())->action('project-a', 'legacy', $response, $this->database);

        $this->assertSame('legacy', $response->document->getId());
        $this->assertSame(Response::MODEL_SCHEDULE, $response->model);
    }

    public function testGetRejectsScheduleWithMismatchedProjectInternalId(): void
    {
        $this->createSchedule('mismatch', projectInternalId: '999');

        $this->expectException(AppwriteException::class);

        (new Get())->action('project-a', 'mismatch', new CapturingSchedulesResponse(), $this->database);
    }

    public function testListIncludesLegacyAndCurrentProjectSchedules(): void
    {
        $this->createSchedule('legacy');
        $this->createSchedule('current', projectInternalId: $this->project->getSequence());
        $this->createSchedule('mismatch', projectInternalId: '999');
        $this->createSchedule('other-project', projectId: 'project-b');

        $response = new CapturingSchedulesResponse();

        (new XList())->action('project-a', [], true, $response, $this->database);

        $schedules = $response->document->getAttribute('schedules');
        $scheduleIds = \array_map(
            fn (Document $schedule) => $schedule->getId(),
            $schedules,
        );

        $this->assertContains('legacy', $scheduleIds);
        $this->assertContains('current', $scheduleIds);
        $this->assertNotContains('mismatch', $scheduleIds);
        $this->assertNotContains('other-project', $scheduleIds);
        $this->assertSame(2, $response->document->getAttribute('total'));
        $this->assertSame(Response::MODEL_SCHEDULE_LIST, $response->model);
    }

    public function testListCursorAllowsLegacyScheduleWithoutProjectInternalId(): void
    {
        $legacy = $this->createSchedule('legacy');
        $this->createSchedule('current', projectInternalId: $this->project->getSequence());

        $response = new CapturingSchedulesResponse();

        (new XList())->action(
            'project-a',
            [Query::cursorAfter($legacy)->toString()],
            true,
            $response,
            $this->database,
        );

        $this->assertSame(Response::MODEL_SCHEDULE_LIST, $response->model);
    }

    private function createSchedule(
        string $id,
        string $projectId = 'project-a',
        int|string|null $projectInternalId = null,
    ): Document {
        $attributes = [
            '$id' => $id,
            'projectId' => $projectId,
        ];

        if ($projectInternalId !== null) {
            $attributes['projectInternalId'] = $projectInternalId;
        }

        return $this->database->createDocument('schedules', new Document($attributes));
    }
}
