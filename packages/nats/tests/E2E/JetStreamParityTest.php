<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\AccountInfo;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\Republish;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\JetStream\StreamSource;
use Utopia\NATS\JetStream\SubjectTransform;

/**
 * E2E tests for the extended JetStream parity features: stream metadata,
 * republish, subject transforms, mirrors/sources, consumer metadata, typed
 * CONSUMER.LIST, per-message TTL and typed account info. Requires a
 * JetStream-enabled server (NATS_URL).
 */
final class JetStreamParityTest extends TestCase
{
    private Connection $conn;
    private JetStream $js;
    /** @var list<string> */
    private array $streams = [];

    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    protected function setUp(): void
    {
        $this->conn = Connection::connect($this->getServerUrl());
        $this->js = $this->conn->jetStream();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            try {
                $this->js->deleteStream($stream);
            } catch (\Throwable) {
                // already gone
            }
        }
        $this->conn->close();
    }

    private function track(string $name): string
    {
        $this->streams[] = $name;
        return $name;
    }

    public function testStreamMetadataRepublishAndSubjectTransform(): void
    {
        $id = uniqid();
        $name = $this->track("PARITY_MRT_{$id}");

        $stream = $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: ["rp.{$id}.>"],
            storage: StorageType::Memory,
            metadata: ['env' => 'test', 'team' => 'core'],
            republish: new Republish(source: "rp.{$id}.>", destination: "pub.{$id}.>", headersOnly: false),
            subjectTransform: new SubjectTransform(source: "rp.{$id}.>", destination: "rp.{$id}.>"),
        ));

        $config = $stream->info()->config;

        $this->assertIsArray($config->metadata);
        $this->assertSame('test', $config->metadata['env'] ?? null);
        $this->assertSame('core', $config->metadata['team'] ?? null);

        $this->assertInstanceOf(Republish::class, $config->republish);
        $this->assertSame("rp.{$id}.>", $config->republish->source);
        $this->assertSame("pub.{$id}.>", $config->republish->destination);

        $this->assertInstanceOf(SubjectTransform::class, $config->subjectTransform);
        $this->assertSame("rp.{$id}.>", $config->subjectTransform->source);

        // Republish must forward stored messages to the destination subject.
        $sub = $this->conn->subscribe("pub.{$id}.>");
        $this->js->publish("rp.{$id}.one", 'hello-republish');

        $received = $sub->nextMessage(3.0);
        $this->assertInstanceOf(\Utopia\NATS\Message::class, $received, 'republish should forward the message');
        $this->assertSame('hello-republish', $received->data);
        $sub->unsubscribe();
    }

    public function testMirrorAndSourceReflectAndReplicate(): void
    {
        $id = uniqid();
        $origin = $this->track("PARITY_ORIG_{$id}");
        $this->js->createStream(new StreamConfig(
            name: $origin,
            subjects: ["ev.{$id}.>"],
            storage: StorageType::Memory,
        ));

        for ($i = 1; $i <= 5; $i++) {
            $this->js->publish("ev.{$id}.a", "m-{$i}");
        }

        // Mirror.
        $mirrorName = $this->track("PARITY_MIR_{$id}");
        $mirror = $this->js->createStream(new StreamConfig(
            name: $mirrorName,
            storage: StorageType::Memory,
            mirror: new StreamSource(name: $origin),
        ));
        $this->assertInstanceOf(StreamSource::class, $mirror->info()->config->mirror);
        $this->assertSame($origin, $mirror->info()->config->mirror->name);

        // Source.
        $sourceName = $this->track("PARITY_SRC_{$id}");
        $sourced = $this->js->createStream(new StreamConfig(
            name: $sourceName,
            storage: StorageType::Memory,
            sources: [new StreamSource(name: $origin)],
        ));
        $sources = $sourced->info()->config->sources;
        $this->assertIsArray($sources);
        $this->assertCount(1, $sources);
        $this->assertSame($origin, $sources[0]->name);

        // Both should replicate the 5 origin messages.
        $mirrorMsgs = $this->waitForMessages($mirrorName, 5);
        $sourceMsgs = $this->waitForMessages($sourceName, 5);
        $this->assertSame(5, $mirrorMsgs, 'mirror must replicate all origin messages');
        $this->assertSame(5, $sourceMsgs, 'source must replicate all origin messages');
    }

    private function waitForMessages(string $stream, int $expected): int
    {
        $count = 0;
        $deadline = microtime(true) + 5.0;
        while ($count < $expected && microtime(true) < $deadline) {
            $count = $this->js->getStreamInfo($stream)->state->messages;
            if ($count < $expected) {
                usleep(100_000);
            }
        }
        return $count;
    }

    public function testConsumerMetadataIsSurfaced(): void
    {
        $id = uniqid();
        $name = $this->track("PARITY_CM_{$id}");
        $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: ["cm.{$id}.>"],
            storage: StorageType::Memory,
        ));

        $consumer = $this->js->createConsumer($name, new ConsumerConfig(
            durableName: "dur_{$id}",
            metadata: ['owner' => 'billing', 'tier' => 'gold'],
        ));

        $info = $consumer->info();
        $this->assertIsArray($info->metadata);
        $this->assertSame('billing', $info->metadata['owner'] ?? null);
        $this->assertSame('gold', $info->metadata['tier'] ?? null);
        $this->assertSame('billing', $info->config->metadata['owner'] ?? null);
    }

    public function testGetConsumersReturnsTypedInfos(): void
    {
        $id = uniqid();
        $name = $this->track("PARITY_CL_{$id}");
        $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: ["cl.{$id}.>"],
            storage: StorageType::Memory,
        ));

        $this->js->createConsumer($name, new ConsumerConfig(durableName: "one_{$id}"));
        $this->js->createConsumer($name, new ConsumerConfig(durableName: "two_{$id}"));

        $consumers = $this->js->getConsumers($name);
        $this->assertCount(2, $consumers);
        foreach ($consumers as $c) {
            $this->assertInstanceOf(ConsumerInfo::class, $c);
            $this->assertSame($name, $c->streamName);
        }

        $names = array_map(static fn(ConsumerInfo $c): string => $c->name, $consumers);
        sort($names);
        $this->assertSame(["one_{$id}", "two_{$id}"], $names);
    }

    public function testPerMessageTtl(): void
    {
        $id = uniqid();
        $name = $this->track("PARITY_TTL_{$id}");

        $config = new StreamConfig(
            name: $name,
            subjects: ["ttl.{$id}.>"],
            storage: StorageType::Memory,
            allowMsgTtl: true,
            subjectDeleteMarkerTtl: 2.0,
        );

        // Client serialization must carry the ADR-43 keys regardless of server support.
        $wire = $config->toArray();
        $this->assertTrue($wire['allow_msg_ttl']);
        $this->assertSame(StreamConfig::secondsToNanos(2.0), $wire['subject_delete_marker_ttl']);

        $this->js->createStream($config);

        // Publish with a per-message TTL; the Nats-TTL header must be stored.
        $ack = $this->js->publish("ttl.{$id}.a", 'expires-soon', ttl: 2);
        $this->assertSame(1, $ack->sequence);

        $stored = $this->js->getMessage($name, 1);
        $this->assertInstanceOf(\Utopia\NATS\Headers::class, $stored->headers);
        $this->assertSame('2s', $stored->headers->get('Nats-TTL'));

        // The message must actually expire (server is NATS 2.11+, per docker-compose).
        $expired = false;
        $deadline = microtime(true) + 6.0;
        while (microtime(true) < $deadline) {
            if ($this->js->getStreamInfo($name)->state->messages === 0) {
                $expired = true;
                break;
            }
            usleep(200_000);
        }
        $this->assertTrue($expired, 'message with TTL must expire');
    }

    public function testAccountInfoReturnsTypedObject(): void
    {
        $id = uniqid();
        $name = $this->track("PARITY_ACC_{$id}");
        $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: ["acc.{$id}.>"],
            storage: StorageType::Memory,
        ));

        $info = $this->js->accountInfo();
        $this->assertInstanceOf(AccountInfo::class, $info);
        $this->assertGreaterThanOrEqual(1, $info->streams);
        $this->assertGreaterThanOrEqual(0, $info->memory);
        $this->assertGreaterThanOrEqual(0, $info->apiTotal);
        $this->assertArrayHasKey('memory', $info->raw);
    }
}
