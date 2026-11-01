<?php

declare(strict_types=1);

namespace Tests\Unit\Screenshots;

use Appwrite\Screenshots\Access;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

/**
 * Moves the project to another team between two steps, once.
 */
final class MovingDatabase extends Database
{
    public ?\Closure $afterUpdate = null;

    #[\Override]
    public function updateDocuments(string $collection, Document $updates, array $queries = [], int $batchSize = self::BATCH_SIZE, ?callable $onNext = null): int
    {
        $count = parent::updateDocuments($collection, $updates, $queries, $batchSize, $onNext);

        if ($this->afterUpdate !== null) {
            $afterUpdate = $this->afterUpdate;
            $this->afterUpdate = null;
            $afterUpdate();
        }

        return $count;
    }
}

final class AccessTest extends TestCase
{
    private MovingDatabase $database;

    private Document $bucket;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = new MovingDatabase(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $this->database
            ->setAuthorization($authorization)
            ->setDatabase('screenshots')
            ->setNamespace('screenshots_access_' . \uniqid());
        $this->database->create();

        $this->database->createCollection(Collection::create(id: 'projects', attributes: [Attribute::string(key: 'teamId')]));
        $this->database->createCollection(Collection::create(id: 'deployments', attributes: [
            Attribute::string(key: 'resourceType'),
            Attribute::string(key: 'screenshotLight'),
            Attribute::string(key: 'screenshotDark'),
        ]));
        $this->database->createCollection(Collection::create(id: 'buckets'));
        $this->bucket = $this->database->createDocument('buckets', new Document(['$id' => Access::BUCKET]));
        $this->database->createCollection(Collection::create(id: 'bucket_' . $this->bucket->getSequence(), attributes: [Attribute::string(key: 'name')]));
    }

    public function testGrantMovesEveryScreenshotToTheProjectsCurrentTeam(): void
    {
        $project = $this->database->createDocument('projects', new Document(['teamId' => 'source']));
        $files = [...$this->deployment($project, 'sites'), ...$this->deployment($project, 'sites')];
        [$functionFile] = $this->deployment($project, 'functions');

        // The caller holds the project as it was before the move
        $this->database->updateDocument('projects', $project->getId(), new Document(['teamId' => 'target']));
        $access = new Access($this->database);

        $this->assertSame(4, $access->grant($this->database, $project, commit: false));
        $this->assertSame([false, false, false, false], $this->readable('target', $files), 'A dry run changes nothing.');

        $this->assertSame(4, $access->grant($this->database, $project));
        $this->assertSame([true, true, true, true], $this->readable('target', $files));
        $this->assertSame([false, false, false, false], $this->readable('source', $files));
        $this->assertSame([true], $this->readable('source', [$functionFile]), 'Only site deployments carry screenshots.');

        $this->assertSame(0, $access->grant($this->database, $project), 'A repeat finds nothing left to change.');
    }

    public function testGrantStartsOverWhenTheProjectMovesWhileItRuns(): void
    {
        $project = $this->database->createDocument('projects', new Document(['teamId' => 'source']));
        $files = $this->deployment($project, 'sites');
        $this->database->updateDocument('projects', $project->getId(), new Document(['teamId' => 'target']));

        $this->database->afterUpdate = fn () => $this->database->updateDocument('projects', $project->getId(), new Document(['teamId' => 'later']));

        (new Access($this->database))->grant($this->database, $project);

        $this->assertSame([true, true], $this->readable('later', $files));
        $this->assertSame([false, false], $this->readable('target', $files));
    }

    public function testGrantDeploymentFixesACaptureStoredAfterTheMove(): void
    {
        $project = $this->database->createDocument('projects', new Document(['teamId' => 'source']));
        $this->database->updateDocument('projects', $project->getId(), new Document(['teamId' => 'target']));

        // Filed under the team on the queued message, after the move's grant already ran
        $earlier = $this->deployment($project, 'sites');
        $captured = $this->deployment($project, 'sites');
        $deployment = $this->database->findOne('deployments', [Query::orderDesc('$sequence')]);

        $this->assertSame(2, (new Access($this->database))->grantDeployment($this->database, $project, $deployment->getId()));

        $this->assertSame([true, true], $this->readable('target', $captured));
        $this->assertSame([false, false], $this->readable('target', $earlier), 'Only that deployment is touched.');
    }

    /**
     * A site or function deployment of the project with both screenshots filed under the team it had when captured.
     *
     * @return list<string> the screenshot file IDs
     */
    private function deployment(Document $project, string $resourceType): array
    {
        $team = $project->getAttribute('teamId');
        $light = $this->database->createDocument('bucket_' . $this->bucket->getSequence(), new Document([
            '$permissions' => [Permission::read(Role::team($team))],
            'name' => 'light.png',
        ]));
        $dark = $this->database->createDocument('bucket_' . $this->bucket->getSequence(), new Document([
            '$permissions' => [Permission::read(Role::team($team))],
            'name' => 'dark.png',
        ]));
        $this->database->createDocument('deployments', new Document([
            'resourceType' => $resourceType,
            'screenshotLight' => $light->getId(),
            'screenshotDark' => $dark->getId(),
        ]));

        return [$light->getId(), $dark->getId()];
    }

    /**
     * Whether a member of the team can read each file, as the console's preview of it checks.
     *
     * @param list<string> $files
     * @return list<bool>
     */
    private function readable(string $teamId, array $files): array
    {
        $authorization = $this->database->getAuthorization();
        $authorization->enable();

        try {
            return $authorization->withRoles(
                [Role::team($teamId)->toString()],
                fn (): array => \array_map(fn (string $id): bool => !$this->database->getDocument('bucket_' . $this->bucket->getSequence(), $id)->isEmpty(), $files),
            );
        } finally {
            $authorization->disable();
        }
    }
}
