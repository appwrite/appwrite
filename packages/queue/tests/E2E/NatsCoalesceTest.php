<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\RetentionPolicy;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\Transport;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Provisioning;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Outcome;
use Utopia\Queue\Queue;
use Utopia\Queue\Tests\DroppingTransport;

/**
 * Keyed coalescing on the NATS broker. Requires a JetStream-enabled server
 * (NATS_URL, default nats://127.0.0.1:14225); skips when unreachable. The
 * subscription checks also need its monitoring endpoint (NATS_MONITOR_URL,
 * default http://127.0.0.1:18222).
 */
final class NatsCoalesceTest extends TestCase
{
    private string $url;
    private Nats $broker;
    private Queue $queue;
    private Connection $admin;
    private JetStream $js;

    protected function setUp(): void
    {
        $this->url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';

        $host = parse_url($this->url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($this->url, PHP_URL_PORT) ?: 4222;
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
        if ($probe === false) {
            $this->markTestSkipped("NATS server not reachable at {$this->url}");
        }
        fclose($probe);

        $this->broker = new Nats(Connection::connect($this->url), ackWait: 2.0, maxDeliver: 3);
        $this->queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));
        $this->admin = Connection::connect($this->url);
        $this->js = $this->admin->jetStream();
    }

    protected function tearDown(): void
    {
        $this->broker->close();
        $this->admin->close();
    }

    public function testCoalescesWhilePending(): void
    {
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 1], 'project'));
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));

        $this->assertSame(1, $this->settled($this->broker, $this->queue, 1));
    }

    public function testCommitFreesTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], 'project');
        $message = $this->receiveOne($this->broker, $this->queue);
        $this->assertSame('project', $message->getKey());

        $this->broker->commit($this->queue, $message);
        $this->drained();

        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));
    }

    public function testNakKeepsTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], 'project');
        $this->broker->reject($this->queue, $this->receiveOne($this->broker, $this->queue));

        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));
        $this->assertSame(1, $this->receiveOne($this->broker, $this->queue)->getPayload()['n']);
    }

    public function testReleaseKeepsTheKey(): void
    {
        $broker = new Nats(Connection::connect($this->url), ackWait: 2.0, maxDeliver: 3, releaseDelay: 0.0);
        try {
            $broker->coalesce($this->queue, ['n' => 1], 'project');
            $broker->release($this->queue, $this->receiveOne($broker, $this->queue));

            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 2], 'project'));
            $this->assertSame(1, $this->receiveOne($broker, $this->queue)->getPayload()['n']);
        } finally {
            $broker->close();
        }
    }

    public function testAckWaitRedeliveryKeepsTheKey(): void
    {
        $broker = new Nats(Connection::connect($this->url), ackWait: 1.0, maxDeliver: 3);
        try {
            $broker->coalesce($this->queue, ['n' => 1], 'project');
            $this->receiveOne($broker, $this->queue);

            $redelivered = $this->receiveOne($broker, $this->queue, 3);
            $this->assertSame(1, $redelivered->getAttempts());

            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 2], 'project'));
        } finally {
            $broker->close();
        }
    }

    public function testTerminalRejectFreesTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], 'project');
        $message = $this->receiveOne($this->broker, $this->queue)->terminal();

        $this->broker->reject($this->queue, $message);
        $this->drained();

        $this->assertSame(1, $this->broker->getFailedCount($this->queue));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));
    }

    public function testExhaustedRejectFreesTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], 'project');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));
            $this->broker->reject($this->queue, $this->receiveOne($this->broker, $this->queue));
        }
        $this->drained();

        $this->assertSame(1, $this->broker->getFailedCount($this->queue));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 3], 'project'));
    }

    public function testPoisonFreesTheKey(): void
    {
        $this->broker->receive($this->queue, 1);
        $this->js->publish($this->keySubject('project'), "\x00not a payload");

        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 1], 'project'));
        $this->assertSame([], $this->broker->receive($this->queue, 2));
        $this->drained();

        $this->assertSame(1, $this->broker->getFailedCount($this->queue));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));
    }

    public function testAdvisoryDeadLetterFreesTheKey(): void
    {
        $broker = new Nats(Connection::connect($this->url), ackWait: 1.0, maxDeliver: 2);
        try {
            // Provisions the queue and subscribes the max-deliveries advisory.
            $this->assertSame([], $broker->receive($this->queue, 1));
            $broker->coalesce($this->queue, ['n' => 1], 'project');

            // Every delivery, spare included, dies unacknowledged on another worker.
            $worker = $this->js->getConsumer($this->workStream(), 'worker');
            for ($delivery = 1; $delivery <= 3; $delivery++) {
                $fetched = 0;
                foreach ($worker->fetch(1, 3.0) as $ignored) {
                    $fetched++;
                }
                $this->assertSame(1, $fetched, "delivery {$delivery}");
            }
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 2], 'project'));

            $deadLettered = false;
            for ($i = 0; $i < 10 && !$deadLettered; $i++) {
                $broker->receive($this->queue, 1);
                $deadLettered = $broker->getFailedCount($this->queue) === 1;
            }
            $this->assertTrue($deadLettered, 'the advisory moved the exhausted message to the dead stream');

            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 3], 'project'));
        } finally {
            $broker->close();
        }
    }

    public function testKeysAreIndependent(): void
    {
        $keys = ['A', 'a', 'a.b', 'a_b', 'a b', '*', '>'];
        foreach ($keys as $key) {
            $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['key' => $key], $key), $key);
        }
        foreach ($keys as $key) {
            $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['key' => $key], $key), $key);
        }

        $this->assertSame(\count($keys), $this->settled($this->broker, $this->queue, \count($keys)));
    }

    public function testUnkeyedPublishIsUnchanged(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $this->broker->publish($this->queue, ['n' => 2]);
        $this->assertSame(2, $this->settled($this->broker, $this->queue, 2));

        $stored = $this->js->getLastMessage($this->workStream(), $this->workSubject());
        $this->assertNull($stored->headers?->get('Nats-Expected-Last-Subject-Sequence'));
        $this->assertSame('application/json', $stored->headers?->get('Content-Type'));

        $envelope = json_decode($stored->data, true);
        $this->assertIsArray($envelope);
        $this->assertSame(['pid', 'queue', 'timestamp', 'payload'], array_keys($envelope));
    }

    public function testConsumerReceivesKeyedAndUnkeyed(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $this->broker->coalesce($this->queue, ['n' => 2], 'project');

        $messages = $this->receiveAll($this->broker, $this->queue, 2);

        $this->assertSame([[1, null], [2, 'project']], array_map(static fn (Message $message): array => [$message->getPayload()['n'], $message->getKey()], $messages));
    }

    public function testExistingQueueIsUpgraded(): void
    {
        $legacy = 'q.' . $this->queue->name . '.priority';
        $this->provisionOldShape([$this->workSubject(), $legacy]);
        $this->js->createConsumer($this->workStream(), new ConsumerConfig(
            durableName: 'worker_priority',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $legacy,
        ));
        $this->js->publish($this->workSubject(), (string) json_encode(['pid' => 'old', 'queue' => $this->queue->name, 'timestamp' => time(), 'payload' => ['n' => 1]]));

        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));

        $messages = $this->receiveAll($this->broker, $this->queue, 2);
        $this->assertSame([[1, null], [2, 'project']], array_map(static fn (Message $message): array => [$message->getPayload()['n'], $message->getKey()], $messages));

        $filters = $this->js->getConsumer($this->workStream(), 'worker')->info()->config->filterSubjects;
        $this->assertSame([$this->workSubject(), $this->workSubject() . '.*'], $filters);
    }

    public function testRevertedStreamSelfHeals(): void
    {
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 1], 'first'));
        $this->revertStream();

        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'second'));
        $this->assertContains($this->workSubject() . '.*', $this->js->getStreamInfo($this->workStream())->config->subjects);
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 3], 'second'));
    }

    public function testRevertedStreamAndConsumerSelfHealAndDeliver(): void
    {
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 1], 'first'));
        $this->revertStream();
        $this->revertConsumer();

        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'second'));

        $filters = $this->js->getConsumer($this->workStream(), 'worker')->info()->config->filterSubjects;
        $this->assertSame([$this->workSubject(), $this->workSubject() . '.*'], $filters);
        $messages = $this->receiveAll($this->broker, $this->queue, 2);
        $this->assertSame([[1, 'first'], [2, 'second']], array_map(static fn (Message $message): array => [$message->getPayload()['n'], $message->getKey()], $messages));
    }

    public function testRevertedConsumerAloneLeavesKeyedMessagesWaiting(): void
    {
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 1], 'first'));
        $this->revertConsumer();

        // The stream still stores key subjects, so nothing triggers a heal.
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'second'));
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 3], 'second'));

        // The next process to provision the queue restores the filter.
        $owner = new Nats(Connection::connect($this->url), ackWait: 2.0, maxDeliver: 3);
        try {
            $messages = $this->receiveAll($owner, $this->queue, 2);
            $this->assertSame([[1, 'first'], [2, 'second']], array_map(static fn (Message $message): array => [$message->getPayload()['n'], $message->getKey()], $messages));
        } finally {
            $owner->close();
        }
    }

    public function testAFetchThroughARevertedConsumerStrandsKeyedMessages(): void
    {
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 1], 'first'));
        $this->revertConsumer();

        // The consumer's scan moves past the keyed message, and restoring its filter does not rewind it.
        $this->assertSame([], $this->broker->receive($this->queue, 1));
        $owner = new Nats(Connection::connect($this->url), ackWait: 2.0, maxDeliver: 3);
        try {
            $this->assertSame([], $owner->receive($this->queue, 1));
            $filters = $this->js->getConsumer($this->workStream(), 'worker')->info()->config->filterSubjects;
            $this->assertSame([$this->workSubject(), $this->workSubject() . '.*'], $filters);

            $this->assertSame(1, $this->js->getStreamInfo($this->workStream())->state->messages);
            $this->assertSame(Outcome::Coalesced, $owner->coalesce($this->queue, ['n' => 2], 'first'));
        } finally {
            $owner->close();
        }
    }

    public function testSelfHealThrowsWhenTheSecondAttemptFails(): void
    {
        $broker = new Nats(Connection::connect($this->url), maxMsgSize: 512);
        try {
            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 1], 'first'));
            $this->revertStream();

            try {
                $broker->coalesce($this->queue, ['n' => str_repeat('x', 1024)], 'second');
                $this->fail('a heal whose republish is refused must throw');
            } catch (JetStreamException $error) {
                $this->assertNotSame(JetStream::ERR_WRONG_LAST_SEQUENCE, $error->apiError?->errCode);
            }
            $this->assertContains($this->workSubject() . '.*', $this->js->getStreamInfo($this->workStream())->config->subjects);
            $this->assertSame(1, $this->js->getStreamInfo($this->workStream())->state->messages);
        } finally {
            $broker->close();
        }
    }

    public function testSelfHealKeepsOneAdvisorySubscription(): void
    {
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 1], 'first'));
        $this->assertSame(1, $this->advisorySubscriptions());
        $this->revertStream();

        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'second'));

        $this->assertSame(1, $this->advisorySubscriptions());
    }

    public function testRequireModeRefusesAnUnupgradedQueue(): void
    {
        $this->provisionOldShape([$this->workSubject()]);

        $adopter = new Nats(Connection::connect($this->url), provisioning: Provisioning::Require);
        try {
            try {
                $adopter->coalesce($this->queue, ['n' => 1], 'project');
                $this->fail('a queue without key subjects must refuse a keyed publish');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('not provisioned for keyed publishing', $error->getMessage());
            }
            $this->assertSame([$this->workSubject()], $this->js->getStreamInfo($this->workStream())->config->subjects);
        } finally {
            $adopter->close();
        }

        // The owner reprovisions, and an adopter then finds the queue keyed.
        $this->broker->receive($this->queue, 1);

        $upgraded = new Nats(Connection::connect($this->url), provisioning: Provisioning::Require);
        try {
            $this->assertSame(Outcome::Published, $upgraded->coalesce($this->queue, ['n' => 1], 'project'));
        } finally {
            $upgraded->close();
        }
    }

    public function testRequireModeSeesAnUpgradeWithoutRestarting(): void
    {
        $this->provisionOldShape([$this->workSubject()]);

        $adopter = new Nats(Connection::connect($this->url), provisioning: Provisioning::Require, adoptInterval: 0.0);
        try {
            try {
                $adopter->coalesce($this->queue, ['n' => 1], 'project');
                $this->fail('a queue without key subjects must refuse a keyed publish');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('not provisioned for keyed publishing', $error->getMessage());
            }

            $this->broker->receive($this->queue, 1);

            $this->assertSame(Outcome::Published, $adopter->coalesce($this->queue, ['n' => 1], 'project'));
            $this->assertSame(Outcome::Coalesced, $adopter->coalesce($this->queue, ['n' => 2], 'project'));
            $this->assertSame(1, $this->receiveOne($this->broker, $this->queue)->getPayload()['n']);
        } finally {
            $adopter->close();
        }
    }

    public function testRequireModeRereadsAnUnupgradedQueueOncePerInterval(): void
    {
        $this->provisionOldShape([$this->workSubject()]);

        $adopter = new Nats(Connection::connect($this->url), provisioning: Provisioning::Require, adoptInterval: 1.0);
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $adopter->coalesce($this->queue, ['n' => 1], 'project');
                    $this->fail('a queue without key subjects must refuse a keyed publish');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('not provisioned for keyed publishing', $error->getMessage());
                }

                // The owner upgrades after the first refusal; the second still inside the interval.
                $this->broker->receive($this->queue, 1);
            }

            usleep(1_100_000);

            $this->assertSame(Outcome::Published, $adopter->coalesce($this->queue, ['n' => 1], 'project'));
        } finally {
            $adopter->close();
        }
    }

    public function testRequireModeReadoptionKeepsOneAdvisorySubscription(): void
    {
        $this->provisionOldShape([$this->workSubject()]);

        $adopter = new Nats(Connection::connect($this->url), provisioning: Provisioning::Require);
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $adopter->coalesce($this->queue, ['n' => 1], 'project');
                    $this->fail('a queue without key subjects must refuse a keyed publish');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('not provisioned for keyed publishing', $error->getMessage());
                }
            }

            $this->assertSame(1, $this->advisorySubscriptions());
        } finally {
            $adopter->close();
        }
    }

    public function testRetryStripsTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], 'project');
        $this->broker->reject($this->queue, $this->receiveOne($this->broker, $this->queue)->terminal());
        $this->drained();
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], 'project'));

        $this->broker->retry($this->queue);

        $stored = $this->js->getLastMessage($this->workStream(), $this->workSubject());
        $envelope = json_decode($stored->data, true);
        $this->assertIsArray($envelope);
        $this->assertArrayNotHasKey('key', $envelope);
        $this->assertSame('application/json', $stored->headers?->get('Content-Type'));

        $messages = $this->receiveAll($this->broker, $this->queue, 2);
        $keys = array_map(static fn (Message $message): ?string => $message->getKey(), $messages);
        sort($keys);
        $this->assertSame([null, 'project'], $keys);
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 3], 'project'));
    }

    public function testDeterministicMessageIdWhilePendingIsCoalesced(): void
    {
        $broker = $this->brokerWithStableIds();
        try {
            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 1], 'project'));
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 1], 'project'));
            $this->assertSame(1, $broker->duplicates());

            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 2], 'project'));
            $this->assertSame(1, $this->settled($broker, $this->queue, 1));
        } finally {
            $broker->close();
        }
    }

    public function testDeterministicMessageIdInsideTheDuplicateWindowIsCoalesced(): void
    {
        $broker = $this->brokerWithStableIds();
        try {
            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 1], 'project'));
            $broker->commit($this->queue, $this->receiveOne($broker, $this->queue));
            $this->drained();

            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 1], 'project'));
            $this->assertSame(1, $broker->duplicates());
            $this->assertSame(0, $this->js->getStreamInfo($this->workStream())->state->messages);

            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 2], 'project'));
        } finally {
            $broker->close();
        }
    }

    public function testRetryAfterALostAckIsPublished(): void
    {
        $armed = true;
        $url = $this->url;
        $broker = new Nats(static function () use ($url, &$armed): Connection {
            return Connection::connect(new ConnectionOptions(
                servers: $url,
                reconnectWait: 0.01,
                transportFactory: static function () use (&$armed): Transport {
                    return new DroppingTransport(new TcpTransport(), $armed);
                },
            ));
        });
        try {
            $this->assertSame(0, $broker->getQueueSize($this->queue));

            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 1], 'project'));

            $this->assertFalse($armed, 'the connection dropped after the publish was written');
            $this->assertSame(1, $broker->duplicates());
            $this->assertSame(1, $this->js->getStreamInfo($this->workStream())->state->messages);
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 2], 'project'));
        } finally {
            $broker->close();
        }
    }

    public function testRetriedDuplicateOfAStableIdIsUnknown(): void
    {
        $armed = false;
        $url = $this->url;
        $broker = new Nats(static function () use ($url, &$armed): Connection {
            return Connection::connect(new ConnectionOptions(
                servers: $url,
                reconnectWait: 0.01,
                transportFactory: static function () use (&$armed): Transport {
                    return new DroppingTransport(new TcpTransport(), $armed);
                },
            ));
        }, ackWait: 2.0, maxDeliver: 3, messageId: static fn (array $payload): string => 'stats-' . json_encode($payload));
        try {
            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 1], 'project'));
            $broker->commit($this->queue, $this->receiveOne($broker, $this->queue));
            $this->drained();

            $armed = true;
            $outcome = null;
            $error = null;
            try {
                $outcome = $broker->coalesce($this->queue, ['n' => 1], 'project');
            } catch (\RuntimeException $caught) {
                $error = $caught;
            }

            $this->assertNull($outcome);
            $this->assertNotNull($error);
            $this->assertStringContainsString('outcome is unknown', $error->getMessage());
            $this->assertSame(1, $broker->duplicates(), 'the retry after the dropped connection was a duplicate');
            $this->assertSame(0, $this->js->getStreamInfo($this->workStream())->state->messages);
        } finally {
            $broker->close();
        }
    }

    public function testCoalescesWithIgbinary(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('igbinary is not installed');
        }
        $broker = new Nats(Connection::connect($this->url), ackWait: 2.0, maxDeliver: 3, codec: new Igbinary());
        try {
            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 1], 'project'));
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($this->queue, ['n' => 2], 'project'));

            $message = $this->receiveOne($broker, $this->queue);
            $this->assertSame(['n' => 1], $message->getPayload());
            $this->assertSame('project', $message->getKey());
            $broker->commit($this->queue, $message);
            $this->drained();

            $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 3], 'project'));
        } finally {
            $broker->close();
        }
    }

    public function testEmptyKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot coalesce with an empty key.');

        $this->broker->coalesce($this->queue, ['n' => 1], '');
    }

    private function brokerWithStableIds(): Nats
    {
        return new Nats(Connection::connect($this->url), ackWait: 2.0, maxDeliver: 3, messageId: static fn (array $payload): string => 'stats-' . json_encode($payload));
    }

    // What an older process's provisioning writes.
    private function revertStream(): void
    {
        $this->js->updateStream(new StreamConfig(
            name: $this->workStream(),
            subjects: [$this->workSubject()],
            retention: RetentionPolicy::WorkQueue,
            duplicateWindow: 120.0,
            metadata: $this->js->getStreamInfo($this->workStream())->config->metadata,
        ));
        $this->assertSame([$this->workSubject()], $this->js->getStreamInfo($this->workStream())->config->subjects);
    }

    private function revertConsumer(): void
    {
        $config = $this->js->getConsumer($this->workStream(), 'worker')->info()->config->toArray();
        unset($config['filter_subjects']);
        $config['filter_subject'] = $this->workSubject();
        $this->js->updateConsumer($this->workStream(), ConsumerConfig::fromArray($config));
        $this->assertNull($this->js->getConsumer($this->workStream(), 'worker')->info()->config->filterSubjects);
    }

    // Counted across every connection, from the server's monitoring endpoint.
    private function advisorySubscriptions(): int
    {
        $monitor = getenv('NATS_MONITOR_URL') ?: 'http://127.0.0.1:18222';
        $body = @file_get_contents($monitor . '/connz?subs=detail&limit=4096', context: stream_context_create(['http' => ['timeout' => 2.0]]));
        if ($body === false) {
            $this->markTestSkipped("NATS monitoring endpoint not reachable at {$monitor}");
        }

        $subject = '$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES.' . $this->workStream() . '.*';
        $connz = json_decode($body, true);
        $this->assertIsArray($connz);
        $connections = $connz['connections'] ?? [];
        $this->assertIsArray($connections);

        $count = 0;
        foreach ($connections as $connection) {
            $this->assertIsArray($connection);
            $subscriptions = $connection['subscriptions_list_detail'] ?? [];
            $this->assertIsArray($subscriptions);
            foreach ($subscriptions as $subscription) {
                $this->assertIsArray($subscription);
                if (($subscription['subject'] ?? null) === $subject) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * The queue as a broker from before keyed publishing provisioned it.
     *
     * @param list<string> $subjects
     */
    private function provisionOldShape(array $subjects): void
    {
        $this->js->createStream(new StreamConfig(
            name: $this->workStream(),
            subjects: $subjects,
            retention: RetentionPolicy::WorkQueue,
            duplicateWindow: 120.0,
        ));
        $this->js->createStream(new StreamConfig(
            name: $this->workStream() . '_DEAD',
            subjects: ['q.' . $this->queue->name . '.dead'],
            retention: RetentionPolicy::WorkQueue,
        ));
        $this->js->createConsumer($this->workStream(), new ConsumerConfig(
            durableName: 'worker',
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $this->workSubject(),
        ));
    }

    private function workStream(): string
    {
        return 'Q_' . strtoupper($this->queue->name);
    }

    private function workSubject(): string
    {
        return 'q.' . $this->queue->name . '.normal';
    }

    private function keySubject(string $key): string
    {
        return $this->workSubject() . '.' . bin2hex($key);
    }

    private function receiveOne(Nats $broker, Queue $queue, int $timeout = 2): Message
    {
        $message = $broker->receive($queue, $timeout)[0] ?? null;
        $this->assertInstanceOf(Message::class, $message);

        return $message;
    }

    /**
     * @return list<Message>
     */
    private function receiveAll(Nats $broker, Queue $queue, int $count): array
    {
        $messages = [];
        for ($i = 0; $i < 5 && \count($messages) < $count; $i++) {
            array_push($messages, ...$broker->receive($queue, 2, $count - \count($messages)));
        }
        $this->assertCount($count, $messages);
        usort($messages, static fn (Message $a, Message $b): int => $a->getSequence() <=> $b->getSequence());

        return $messages;
    }

    // An ack or TERM is fire-and-forget, so the server removes the message shortly after.
    private function drained(): void
    {
        $stored = $this->js->getStreamInfo($this->workStream())->state->messages;
        for ($i = 0; $i < 50 && $stored !== 0; $i++) {
            usleep(20_000);
            $stored = $this->js->getStreamInfo($this->workStream())->state->messages;
        }
        $this->assertSame(0, $stored, 'the settled message is removed from the work stream');
    }

    // JetStream updates a consumer's num_pending asynchronously after the publish ack.
    private function settled(Nats $broker, Queue $queue, int $expected): int
    {
        $size = $broker->getQueueSize($queue);
        for ($i = 0; $i < 50 && $size !== $expected; $i++) {
            usleep(20_000);
            $size = $broker->getQueueSize($queue);
        }

        return $size;
    }
}
