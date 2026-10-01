<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destination;
use Utopia\Migration\Source;
use Utopia\Migration\Sources\Appwrite as SourceAppwrite;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

final class MigrationsTest extends TestCase
{
    public const string BUILT = 'source:build';

    public function testAppwriteSourceWithPrivateEndpointFailsBeforeTheSourceIsBuilt(): void
    {
        foreach (['http://169.254.169.254/v1', 'http://127.0.0.1/v1', 'http://[::7f00:1]/v1', 'gopher://1.1.1.1/'] as $endpoint) {
            [$migration, $events] = $this->migrate($endpoint);

            $this->assertSame('failed', $migration->getAttribute('status'), $endpoint);
            $this->assertNotContains(self::BUILT, $events, $endpoint);
            $this->assertStringContainsString('Invalid `endpoint`', \implode(',', $migration->getAttribute('errors')));
        }
    }

    public function testAppwriteSourceAcceptsAnAllowedEndpoint(): void
    {
        $allowlist = \getenv('_APP_MIGRATIONS_ALLOWED_HOSTS');

        try {
            [, $refused] = $this->migrate('http://10.0.0.5/v1');

            \putenv('_APP_MIGRATIONS_ALLOWED_HOSTS=10.0.0.0/8');

            [, $allowed] = $this->migrate('http://10.0.0.5/v1');
        } finally {
            \putenv($allowlist === false ? '_APP_MIGRATIONS_ALLOWED_HOSTS' : '_APP_MIGRATIONS_ALLOWED_HOSTS=' . $allowlist);
        }

        $this->assertNotContains(self::BUILT, $refused);
        $this->assertContains(self::BUILT, $allowed);
    }

    public function testAppwriteSourceSkipsRevalidationOnlyForTheInternalEndpoint(): void
    {
        [, $internal] = $this->migrate('http://localhost/v1');
        [, $other] = $this->migrate('http://localhost:8080/v1');

        $this->assertContains(self::BUILT, $internal);
        $this->assertNotContains(self::BUILT, $other);
    }

    /**
     * @return array{Document, array<string>}
     */
    private function migrate(string $endpoint): array
    {
        $events = [];
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $worker = new class ($record) extends Migrations {
            public function __construct(private readonly \Closure $record)
            {
                $this->logError = static fn () => null;
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
                ($this->record)(MigrationsTest::BUILT);

                throw new \RuntimeException('Stop after the source is built');
            }

            #[\Override]
            protected function processDestination(Document $migration): Destination
            {
                throw new \RuntimeException('Unreachable');
            }

            #[\Override]
            protected function updateMigrationDocument(
                Document $migration,
                Document $project,
                Realtime $queueForRealtime,
            ): Document {
                return $migration;
            }
        };

        $migration = new Document([
            '$id' => 'migration',
            '$sequence' => 1,
            'credentials' => ['endpoint' => $endpoint],
            'destination' => 'TestDestination',
            'options' => [],
            'resourceId' => '',
            'resourceType' => '',
            'resources' => [],
            'source' => SourceAppwrite::getName(),
            'stage' => 'pending',
            'status' => 'pending',
        ]);

        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $host = \getenv('_APP_MIGRATION_HOST');
        \putenv('_APP_MIGRATION_HOST=localhost');

        try {
            $worker->process(
                $migration,
                new Document(['$id' => 'project', '$sequence' => 1]),
                new Realtime(),
                new MailPublisher($publisher, $queue),
                new Context(),
                new UsagePublisher($publisher, $queue),
                new Authorization(),
            );
        } finally {
            \putenv($host === false ? '_APP_MIGRATION_HOST' : '_APP_MIGRATION_HOST=' . $host);
        }

        return [$migration, $events];
    }
}
