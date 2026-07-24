<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Certificates\Adapter as CertificatesAdapter;
use Appwrite\Event\Delivery\Fanout;
use Appwrite\Event\Delivery\Receipt;
use Appwrite\Event\Delivery\Sink;
use Appwrite\Platform\Workers\Deletes;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Storage\Device;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    public function testProjectDeleteScopesAlertsByProjectIdAndProjectInternalId(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('deleteReceipts')
            ->setNamespace('delete_receipts_' . \uniqid());
        $database->create();

        $collection = (require __DIR__ . '/../../../../app/config/collections.php')['console']['eventReceipts'];
        $database->createCollection(
            'eventReceipts',
            \array_map(
                static fn (array $attribute): Document => new Document($attribute),
                $collection['attributes']
            ),
            \array_map(
                static fn (array $index): Document => new Document($index),
                $collection['indexes']
            ),
        );

        $delivery = new Fanout(new Receipt($database));
        foreach (['project-internal-1', 'project-internal-2'] as $projectInternalId) {
            $delivery->deliver(
                projectId: 'project-1',
                projectInternalId: $projectInternalId,
                envelopeId: 'envelope-1',
                sink: Sink::Webhook,
                targetId: 'webhook-1',
                delivery: static function (): void {
                },
            );
        }

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

                if ($collection === 'eventReceipts') {
                    parent::deleteByGroup($collection, $queries, $database, $callback);
                }
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

        $this->assertArrayHasKey('eventReceipts', $worker->groups);
        $receiptQueries = $worker->groups['eventReceipts'];
        $this->assertSame(Query::TYPE_EQUAL, $receiptQueries[0]->getMethod());
        $this->assertSame('projectId', $receiptQueries[0]->getAttribute());
        $this->assertSame(['project-1'], $receiptQueries[0]->getValues());
        $this->assertSame(Query::TYPE_EQUAL, $receiptQueries[1]->getMethod());
        $this->assertSame('projectInternalId', $receiptQueries[1]->getAttribute());
        $this->assertSame(['project-internal-1'], $receiptQueries[1]->getValues());
        $this->assertSame(Query::TYPE_ORDER_ASC, $receiptQueries[2]->getMethod());

        $deletedIdentity = $delivery->getIdentity(
            'project-1',
            'project-internal-1',
            'envelope-1',
            Sink::Webhook,
            'webhook-1',
        );
        $recreatedIdentity = $delivery->getIdentity(
            'project-1',
            'project-internal-2',
            'envelope-1',
            Sink::Webhook,
            'webhook-1',
        );
        $this->assertTrue($database->getDocument('eventReceipts', $deletedIdentity)->isEmpty());
        $this->assertFalse($database->getDocument('eventReceipts', $recreatedIdentity)->isEmpty());

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
