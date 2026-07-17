<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Usage\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destination;
use Utopia\Migration\Source;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

final class MigrationsTest extends TestCase
{
    public function testSuccessHooksRunBeforeCompletedMigrationIsPersisted(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
            });
        $source->expects($this->never())->method('error');
        $destination->expects($this->never())->method('error');

        $migration = $this->createMigration();
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('completed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'destination:success',
            'source:success',
            'persist:completed:finished',
        ], $events);
    }

    public function testThrowingSourceSuccessHookPersistsFailedMigrationWithoutRerunningHooks(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
                throw new \RuntimeException('Finalization failed');
            });
        $source
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:error';
            });
        $destination
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:error';
            });

        $migration = $this->createMigration();
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('failed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'destination:success',
            'source:success',
            'persist:failed:finished',
            'source:error',
            'destination:error',
        ], $events);
        $this->assertNotContains('persist:completed:finished', $events);
    }

    public function testNullResourceTypeUsesEmptyResourceSelector(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
            });
        $source->expects($this->never())->method('error');
        $destination->expects($this->never())->method('error');

        $migration = $this->createMigration(null);
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('completed', $migration->getAttribute('status'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'destination:success',
            'source:success',
            'persist:completed:finished',
        ], $events);
    }

    private function createSourceMock(): Source&MockObject
    {
        $target = $this->createMock(Source::class);
        $target->method('getErrors')->willReturn([]);
        $target->expects($this->once())->method('shutdown');
        $target->expects($this->once())->method('cleanUp');

        return $target;
    }

    private function createDestinationMock(): Destination&MockObject
    {
        $target = $this->createMock(Destination::class);
        $target->method('getErrors')->willReturn([]);
        $target->expects($this->once())->method('shutdown');
        $target->expects($this->once())->method('cleanUp');

        return $target;
    }

    private function createMigration(?string $resourceType = ''): Document
    {
        return new Document([
            '$id' => 'migration',
            '$sequence' => 1,
            'credentials' => [],
            'destination' => 'TestDestination',
            'options' => [],
            'resourceId' => '',
            'resourceType' => $resourceType,
            'resources' => [],
            'source' => 'TestSource',
            'stage' => 'pending',
            'status' => 'pending',
        ]);
    }

    /**
     * @param array<string> $events
     */
    private function createProcessor(Source $source, Destination $destination, array &$events): \Closure
    {
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };
        $worker = new class ($source, $destination, $record) extends Migrations {
            public function __construct(
                private readonly Source $migrationSource,
                private readonly Destination $migrationDestination,
                private readonly \Closure $record,
            ) {
            }

            public function process(
                Document $migration,
                Document $project,
                Realtime $queueForRealtime,
                MailPublisher $publisherForMails,
                Context $usage,
                UsagePublisher $publisherForUsage,
                Authorization $authorization,
            ): void {
                $this->project = $project;
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

            #[\Override]
            protected function generateAPIKey(Document $project): string
            {
                return 'key';
            }

            #[\Override]
            protected function processSource(Document $migration): Source
            {
                return $this->migrationSource;
            }

            #[\Override]
            protected function processDestination(Document $migration): Destination
            {
                return $this->migrationDestination;
            }

            #[\Override]
            protected function updateMigrationDocument(
                Document $migration,
                Document $project,
                Realtime $queueForRealtime,
            ): Document {
                ($this->record)('persist:'
                    . $migration->getAttribute('status')
                    . ':'
                    . $migration->getAttribute('stage'));

                return $migration;
            }
        };

        return $worker->process(...);
    }

    private function process(\Closure $processor, Document $migration): void
    {
        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $host = \getenv('_APP_MIGRATION_HOST');
        \putenv('_APP_MIGRATION_HOST=localhost');

        try {
            $processor(
                $migration,
                new Document([
                    '$id' => 'project',
                    '$sequence' => 1,
                ]),
                new Realtime(),
                new MailPublisher($publisher, $queue),
                new Context(),
                new UsagePublisher($publisher, $queue),
                new Authorization(),
            );
        } finally {
            \putenv($host === false ? '_APP_MIGRATION_HOST' : '_APP_MIGRATION_HOST=' . $host);
        }
    }
}
