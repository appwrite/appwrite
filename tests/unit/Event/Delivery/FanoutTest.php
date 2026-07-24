<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Delivery;

use Appwrite\Event\Delivery\Fanout;
use Appwrite\Event\Delivery\Receipt;
use Appwrite\Event\Delivery\Sink;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../app/init.php';

final class FanoutTest extends TestCase
{
    public function testRetrySkipsCompletedSinkAndResumesMissingSink(): void
    {
        $fanout = $this->createFanout();
        $calls = [
            Sink::Webhook->value => 0,
            Sink::Function->value => 0,
        ];

        $this->assertTrue($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
            envelopeId: 'envelope-1',
            sink: Sink::Webhook,
            targetId: 'webhook-1',
            delivery: function () use (&$calls): void {
                $calls[Sink::Webhook->value]++;
            },
        ));

        try {
            $fanout->deliver(
                projectId: 'project-1',
                projectInternalId: 'project-internal-1',
                envelopeId: 'envelope-1',
                sink: Sink::Function,
                targetId: 'function-1',
                delivery: function () use (&$calls): void {
                    $calls[Sink::Function->value]++;
                    throw new RuntimeException('crash before receipt');
                },
            );
            $this->fail('Expected the simulated crash to propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('crash before receipt', $error->getMessage());
        }

        $this->assertFalse($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
            envelopeId: 'envelope-1',
            sink: Sink::Webhook,
            targetId: 'webhook-1',
            delivery: function () use (&$calls): void {
                $calls[Sink::Webhook->value]++;
            },
        ));
        $this->assertTrue($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
            envelopeId: 'envelope-1',
            sink: Sink::Function,
            targetId: 'function-1',
            delivery: function () use (&$calls): void {
                $calls[Sink::Function->value]++;
            },
        ));
        $this->assertFalse($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'project-internal-1',
            envelopeId: 'envelope-1',
            sink: Sink::Function,
            targetId: 'function-1',
            delivery: function () use (&$calls): void {
                $calls[Sink::Function->value]++;
            },
        ));

        $this->assertSame(1, $calls[Sink::Webhook->value]);
        $this->assertSame(2, $calls[Sink::Function->value]);
    }

    public function testIdentityIsStableAndScopedByProjectGenerationSinkAndTarget(): void
    {
        $fanout = $this->createFanout();
        $identity = $fanout->getIdentity('project-1', 'project-internal-1', 'envelope-1', Sink::Function, 'function-1');

        $this->assertSame(
            $identity,
            $fanout->getIdentity('project-1', 'project-internal-1', 'envelope-1', Sink::Function, 'function-1')
        );
        $this->assertNotSame(
            $identity,
            $fanout->getIdentity('project-2', 'project-internal-1', 'envelope-1', Sink::Function, 'function-1')
        );
        $this->assertNotSame(
            $identity,
            $fanout->getIdentity('project-1', 'project-internal-2', 'envelope-1', Sink::Function, 'function-1')
        );
        $this->assertNotSame(
            $identity,
            $fanout->getIdentity('project-1', 'project-internal-1', 'envelope-1', Sink::Webhook, 'function-1')
        );
        $this->assertNotSame(
            $identity,
            $fanout->getIdentity('project-1', 'project-internal-1', 'envelope-1', Sink::Function, 'function-2')
        );
    }

    public function testRecreatedProjectGenerationSurvivesOldCleanupAndLateReceipt(): void
    {
        $database = $this->createDatabase();
        $fanout = new Fanout(new Receipt($database));
        $calls = [
            'generation-a' => 0,
            'generation-b' => 0,
        ];

        $this->assertTrue($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'generation-a',
            envelopeId: 'envelope-1',
            sink: Sink::Webhook,
            targetId: 'webhook-1',
            delivery: function () use (&$calls): void {
                $calls['generation-a']++;
            },
        ));
        $this->assertTrue($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'generation-b',
            envelopeId: 'envelope-1',
            sink: Sink::Webhook,
            targetId: 'webhook-1',
            delivery: function () use (&$calls): void {
                $calls['generation-b']++;
            },
        ));

        $identityA = $fanout->getIdentity(
            'project-1',
            'generation-a',
            'envelope-1',
            Sink::Webhook,
            'webhook-1',
        );
        $identityB = $fanout->getIdentity(
            'project-1',
            'generation-b',
            'envelope-1',
            Sink::Webhook,
            'webhook-1',
        );
        $this->assertNotSame($identityA, $identityB);
        $this->assertSame('generation-b', $database->getDocument('eventReceipts', $identityB)->getAttribute('projectInternalId'));

        $database->deleteDocuments('eventReceipts', [
            Query::equal('projectId', ['project-1']),
            Query::equal('projectInternalId', ['generation-a']),
        ]);
        $this->assertTrue($database->getDocument('eventReceipts', $identityA)->isEmpty());
        $this->assertFalse($database->getDocument('eventReceipts', $identityB)->isEmpty());

        $this->assertTrue($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'generation-a',
            envelopeId: 'envelope-1',
            sink: Sink::Webhook,
            targetId: 'webhook-1',
            delivery: function () use (&$calls): void {
                $calls['generation-a']++;
            },
        ));
        $this->assertFalse($fanout->deliver(
            projectId: 'project-1',
            projectInternalId: 'generation-b',
            envelopeId: 'envelope-1',
            sink: Sink::Webhook,
            targetId: 'webhook-1',
            delivery: function () use (&$calls): void {
                $calls['generation-b']++;
            },
        ));

        $this->assertSame(2, $calls['generation-a']);
        $this->assertSame(1, $calls['generation-b']);
    }

    public function testLegacyEnvelopeBypassesReceiptState(): void
    {
        $fanout = $this->createFanout();
        $calls = 0;

        foreach ([1, 2] as $_) {
            $this->assertTrue($fanout->deliver(
                projectId: 'project-1',
                projectInternalId: 'project-internal-1',
                envelopeId: '',
                sink: Sink::Webhook,
                targetId: 'webhook-1',
                delivery: function () use (&$calls): void {
                    $calls++;
                },
            ));
        }

        $this->assertSame(2, $calls);
    }

    public function testEnvelopeRequiresProjectGenerationBeforeTargetDelivery(): void
    {
        $fanout = $this->createFanout();
        $calls = 0;

        try {
            $fanout->deliver(
                projectId: 'project-1',
                projectInternalId: '',
                envelopeId: 'envelope-1',
                sink: Sink::Webhook,
                targetId: 'webhook-1',
                delivery: function () use (&$calls): void {
                    $calls++;
                },
            );
            $this->fail('Expected missing project generation to be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Project ID and internal ID are required for receipt-backed delivery.', $error->getMessage());
        }

        $this->assertSame(0, $calls);
    }

    private function createFanout(): Fanout
    {
        return new Fanout(new Receipt($this->createDatabase()));
    }

    private function createDatabase(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('eventReceipts')
            ->setNamespace('event_receipts_' . \uniqid());
        $database->create();

        $collections = require __DIR__ . '/../../../../app/config/collections.php';
        $collection = $collections['console']['eventReceipts'];
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

        return $database;
    }
}
