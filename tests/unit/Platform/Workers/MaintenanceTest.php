<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Platform\Workers\Maintenance;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Maintenance\Projects;
use Tests\Unit\Maintenance\QueuePublisher;
use Utopia\Database\Adapter\Memory;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;

require_once __DIR__ . '/../../../../app/init.php';

final class MaintenanceTest extends TestCase
{
    private string|false $key;

    private string|false $region;

    protected function setUp(): void
    {
        // Projects default smtp and oAuthProviders through the encrypt filter on every write and read.
        $this->key = \getenv('_APP_OPENSSL_KEY_V1');
        $this->region = \getenv('_APP_REGION');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
        \putenv('_APP_REGION=east');
    }

    protected function tearDown(): void
    {
        \putenv($this->key === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->key);
        \putenv($this->region === false ? '_APP_REGION' : '_APP_REGION=' . $this->region);
    }

    public function testMessageEnqueuesMaintenanceForTheSlotsInactiveProjects(): void
    {
        $slots = 4;
        $due = \intdiv(\time(), 300) * 300;
        $slot = \intdiv($due, 300) % $slots;
        $base = $slot * 100;
        $when = new \DateTimeImmutable('@' . $due);

        $database = Projects::seed(new Memory(), [
            ['inactive', $base + 1, $when->modify('-40 days'), 'east'],
            ['never', $base + 2, null, 'east'],
            ['active', $base + 3, $when->modify('-1 day'), 'east'],
            ['deleted', $base + 4, null, 'east'],
            ['other', (($slot + 1) % $slots) * 100 + 5, null, 'east'],
        ]);
        $database->skipFilters(fn (): bool => $database->deleteDocument('projects', 'deleted'), APP_PROJECTS_SUBQUERIES);

        $queue = new QueuePublisher(new TestClock($when), 0);
        $resources = [
            'message' => new Message([
                'pid' => 'pid',
                'queue' => 'v1-maintenance',
                'timestamp' => $due,
                'payload' => (new MaintenanceMessage(slot: $slot, slots: $slots, due: $due, period: $slots * 300))->toArray(),
            ]),
            'dbForPlatform' => $database,
            'publisherForDeletes' => new DeletePublisher($queue, new Queue('v1-deletes')),
        ];

        $worker = new Maintenance();
        ($worker->getCallback())(...\array_map(fn (string $name): mixed => $resources[$name], $worker->getInjections()));

        $published = \array_map(fn (array $payload): DeleteMessage => DeleteMessage::fromArray($payload), $queue->payloads('v1-deletes'));
        $ids = \array_map(fn (DeleteMessage $message): string => (string) $message->project?->getId(), $published);
        \sort($ids);

        $this->assertSame(['inactive', 'never'], $ids);
        $this->assertSame([DELETE_TYPE_MAINTENANCE], \array_values(\array_unique(\array_map(fn (DeleteMessage $message): string => $message->type, $published))));
    }
}
