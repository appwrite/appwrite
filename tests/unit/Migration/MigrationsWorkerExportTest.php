<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destination;
use Utopia\Migration\Destinations\JSON as DestinationJSON;
use Utopia\Migration\Source;
use Utopia\Storage\Device;

final class MigrationsWorkerExportTest extends TestCase
{
    public function testExportCompletionFinalizesWithoutInitiatingUser(): void
    {
        $file = null;
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('findOne');
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('buckets', 'default')
            ->willReturn(new Document([
                '$id' => 'default',
                '$sequence' => 1,
            ]));
        $dbForPlatform
            ->expects($this->once())
            ->method('createDocument')
            ->with(
                'bucket_1',
                $this->callback(function (Document $document) use (&$file): bool {
                    $file = $document;
                    return true;
                })
            )
            ->willReturnArgument(1);

        $deviceForFiles = $this->createMock(Device::class);
        $deviceForFiles
            ->expects($this->once())
            ->method('getPath')
            ->with('default/export.json')
            ->willReturn('/tmp/export.json');
        $deviceForFiles->expects($this->once())->method('getFileSize')->with('/tmp/export.json')->willReturn(256);
        $deviceForFiles->expects($this->once())->method('getFileMimeType')->with('/tmp/export.json')->willReturn('application/json');
        $deviceForFiles->expects($this->once())->method('getFileHash')->with('/tmp/export.json')->willReturn('signature');

        $worker = new class () extends Migrations {
            public function complete(
                Database $dbForPlatform,
                Device $deviceForFiles,
                Document $migration,
                MailPublisher $publisherForMails,
                Realtime $queueForRealtime,
                Authorization $authorization,
            ): void {
                $this->dbForPlatform = $dbForPlatform;
                $this->deviceForFiles = $deviceForFiles;
                $this->plan = [];
                $this->handleDataExportComplete(
                    new Document(['$id' => 'project-id']),
                    $migration,
                    $publisherForMails,
                    $queueForRealtime,
                    [],
                    $authorization,
                );
            }

            protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
            {
                return $migration;
            }
        };

        $migration = new Document([
            '$id' => 'migration-without-user',
            'destination' => DestinationJSON::getName(),
            'options' => [
                'filename' => 'export',
                'notify' => false,
                'userInternalId' => '',
            ],
        ]);

        $previousKey = \getenv('_APP_OPENSSL_KEY_V1');
        $previousDomain = \getenv('_APP_DOMAIN');
        \putenv('_APP_OPENSSL_KEY_V1=test-key');
        \putenv('_APP_DOMAIN=example.test');

        try {
            $worker->complete(
                $dbForPlatform,
                $deviceForFiles,
                $migration,
                $this->createStub(MailPublisher::class),
                $this->createStub(Realtime::class),
                $this->createStub(Authorization::class),
            );
        } finally {
            $this->restoreEnvironment('_APP_OPENSSL_KEY_V1', $previousKey);
            $this->restoreEnvironment('_APP_DOMAIN', $previousDomain);
        }

        $this->assertInstanceOf(Document::class, $file);
        $this->assertSame([], $file->getPermissions());
        $this->assertStringContainsString(
            '/v1/storage/buckets/default/files/',
            (string) $migration->getAttribute('options')['downloadUrl']
        );
    }

    public function testExportCompletionNormalizesStringUserInternalId(): void
    {
        $user = new Document([
            '$id' => 'user-id',
            '$sequence' => 42,
        ]);
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('findOne')
            ->with(
                'users',
                $this->callback(function (array $queries): bool {
                    $this->assertCount(1, $queries);
                    $this->assertInstanceOf(Query::class, $queries[0]);
                    $this->assertSame([42], $queries[0]->getValues());

                    return true;
                })
            )
            ->willReturn($user);

        $worker = new class () extends Migrations {
            public function resolve(Database $dbForPlatform, Document $migration): Document
            {
                $this->dbForPlatform = $dbForPlatform;
                return $this->resolveExportUser($migration);
            }
        };

        $resolved = $worker->resolve($dbForPlatform, new Document([
            '$id' => 'migration-with-string-user',
            'options' => ['userInternalId' => '42'],
        ]));

        $this->assertSame('user-id', $resolved->getId());
    }

    public function testExportUserLookupFailurePropagates(): void
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform
            ->method('findOne')
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $worker = new class () extends Migrations {
            public function resolve(Database $dbForPlatform, Document $migration): Document
            {
                $this->dbForPlatform = $dbForPlatform;
                return $this->resolveExportUser($migration);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database unavailable');

        $worker->resolve($dbForPlatform, new Document([
            '$id' => 'migration-with-user',
            'options' => ['userInternalId' => 42],
        ]));
    }

    public function testNotificationAndLoggerFailuresDoNotEscape(): void
    {
        $reported = 0;
        $worker = new class () extends Migrations {
            public function notify(Document $migration, MailPublisher $publisherForMails, callable $logError): void
            {
                $this->logError = $logError;
                $this->notifyExport(
                    migration: $migration,
                    success: true,
                    project: new Document([]),
                    user: new Document([]),
                    options: ['notify' => true],
                    publisherForMails: $publisherForMails,
                    platform: [],
                );
            }

            protected function sendExportEmail(
                bool $success,
                Document $project,
                Document $user,
                array $options,
                MailPublisher $publisherForMails,
                array $platform,
                string $exportType = 'CSV',
                string $downloadUrl = '',
                float $sizeMB = 0.0,
            ): void {
                throw new \RuntimeException('Mail unavailable');
            }
        };

        $worker->notify(
            new Document([
                '$id' => 'migration-id',
                'source' => 'Appwrite',
                'destination' => DestinationJSON::getName(),
            ]),
            $this->createStub(MailPublisher::class),
            function () use (&$reported): void {
                $reported++;
                throw new \RuntimeException('Logger unavailable');
            }
        );

        $this->assertSame(1, $reported);
    }

    public function testArtifactFinalizationFailureMarksMigrationFailed(): void
    {
        $source = $this->createStub(Source::class);
        $source->method('getErrors')->willReturn([]);
        $destination = $this->createStub(Destination::class);
        $destination->method('getErrors')->willReturn([]);

        $worker = new class ($source, $destination) extends Migrations {
            /** @var array<string> */
            public array $statuses = [];

            public function __construct(private Source $source, private Destination $destination)
            {
            }

            public function process(
                Document $migration,
                Realtime $queueForRealtime,
                MailPublisher $publisherForMails,
                Context $usage,
                UsagePublisher $publisherForUsage,
                Authorization $authorization,
            ): void {
                $this->project = new Document([
                    '$id' => 'project-id',
                    '$sequence' => 1,
                ]);
                $this->logError = static function (): void {
                };

                $this->processMigration(
                    $migration,
                    $queueForRealtime,
                    $publisherForMails,
                    $usage,
                    $publisherForUsage,
                    [],
                    $authorization,
                );
            }

            protected function processSource(Document $migration): Source
            {
                return $this->source;
            }

            protected function processDestination(Document $migration): Destination
            {
                return $this->destination;
            }

            protected function handleDataExportComplete(
                Document $project,
                Document $migration,
                MailPublisher $publisherForMails,
                Realtime $queueForRealtime,
                array $platform,
                Authorization $authorization,
            ): void {
                throw new \RuntimeException('Artifact unavailable');
            }

            protected function generateAPIKey(Document $project): string
            {
                return 'api-key';
            }

            protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime): Document
            {
                $this->statuses[] = (string) $migration->getAttribute('status');
                return $migration;
            }
        };

        $migration = new Document([
            '$id' => 'migration-id',
            '$sequence' => 1,
            'credentials' => [],
            'destination' => DestinationJSON::getName(),
            'resourceId' => '',
            'resourceType' => '',
            'resources' => [],
            'source' => 'Test',
        ]);

        $previousHost = \getenv('_APP_MIGRATION_HOST');
        \putenv('_APP_MIGRATION_HOST=example.test');

        try {
            $worker->process(
                $migration,
                $this->createStub(Realtime::class),
                $this->createStub(MailPublisher::class),
                $this->createStub(Context::class),
                $this->createStub(UsagePublisher::class),
                $this->createStub(Authorization::class),
            );
        } finally {
            $this->restoreEnvironment('_APP_MIGRATION_HOST', $previousHost);
        }

        $this->assertSame('failed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));
        $this->assertNotContains('completed', $worker->statuses);
        $this->assertSame('failed', $worker->statuses[array_key_last($worker->statuses)]);
        $this->assertNotEmpty($migration->getAttribute('errors'));
    }

    /**
     * @param string|false $value
     */
    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            \putenv($name);
            return;
        }

        \putenv($name . '=' . $value);
    }
}
