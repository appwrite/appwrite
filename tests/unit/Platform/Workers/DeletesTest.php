<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Certificates\Adapter as CertificatesAdapter;
use Appwrite\Platform\Workers\Deletes;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Storage\Device;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    public function testProjectDeleteScopesAlertsByProjectIdAndProjectInternalId(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 'project-internal-1',
            'database' => 'mysql://localhost/appwrite',
        ]);

        $worker = new class () extends Deletes {
            /**
             * @var array<string, array<Query>>
             */
            public array $groups = [];

            public function runProjectDelete(
                Database $database,
                Document $project,
                callable $getProjectDB,
                callable $getDatabasesDB,
                Device $device,
                CertificatesAdapter $certificates,
            ): void {
                $this->deleteProject(
                    $database,
                    $getProjectDB,
                    $getDatabasesDB,
                    $device,
                    $device,
                    $device,
                    $device,
                    $device,
                    $certificates,
                    $project,
                );
            }

            protected function deleteByGroup(string $collection, array $queries, Database $database, ?callable $callback = null): void
            {
                $this->groups[$collection] = $queries;
            }
        };
        $getProjectDB = static fn () => throw new \RuntimeException('stop');
        $getDatabasesDB = static fn () => $database;

        try {
            $worker->runProjectDelete(
                $database,
                $project,
                $getProjectDB,
                $getDatabasesDB,
                $this->createStub(Device::class),
                $this->createStub(CertificatesAdapter::class),
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame('stop', $exception->getMessage());
        }

        $this->assertArrayHasKey('notifications', $worker->groups);

        $queries = $worker->groups['notifications'];
        $this->assertSame(Query::TYPE_EQUAL, $queries[0]->getMethod());
        $this->assertSame('projectId', $queries[0]->getAttribute());
        $this->assertSame(['project-1'], $queries[0]->getValues());

        $this->assertSame(Query::TYPE_EQUAL, $queries[1]->getMethod());
        $this->assertSame('projectInternalId', $queries[1]->getAttribute());
        $this->assertSame(['project-internal-1'], $queries[1]->getValues());

        $this->assertSame(Query::TYPE_ORDER_ASC, $queries[2]->getMethod());
    }
}
