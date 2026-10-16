<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\JetStream;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\JetStream\SequenceInfo;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\JetStream\StreamConsumerSource;
use Utopia\NATS\JetStream\StreamMessage;
use Utopia\NATS\JetStream\StreamSource;
use Utopia\NATS\JetStream\SubjectTransform;

final class ConfigTest extends TestCase
{
    public function testPushAndFlowControlFieldsSerialize(): void
    {
        $config = new ConsumerConfig(
            deliverSubject: 'deliver.here',
            deliverGroup: 'group-a',
            flowControl: true,
            idleHeartbeat: 2.0,
        );

        $arr = $config->toArray();
        $this->assertSame('deliver.here', $arr['deliver_subject']);
        $this->assertSame('group-a', $arr['deliver_group']);
        $this->assertTrue($arr['flow_control']);
        $this->assertSame(2_000_000_000, $arr['idle_heartbeat']);
    }

    public function testConsumerConfigRoundTrip(): void
    {
        $config = ConsumerConfig::fromArray([
            'deliver_subject' => 'x.y',
            'flow_control' => true,
            'idle_heartbeat' => 5_000_000_000,
        ]);

        $this->assertSame('x.y', $config->deliverSubject);
        $this->assertTrue($config->flowControl);
        $this->assertEqualsWithDelta(5.0, $config->idleHeartbeat, PHP_FLOAT_EPSILON);
    }

    public function testConsumerInfoPopulatesSequences(): void
    {
        $info = ConsumerInfo::fromArray([
            'stream_name' => 'S',
            'name' => 'C',
            'num_pending' => 7,
            'num_ack_pending' => 3,
            'delivered' => ['consumer_seq' => 4, 'stream_seq' => 10],
            'ack_floor' => ['consumer_seq' => 1, 'stream_seq' => 7],
        ]);

        $this->assertSame(7, $info->numPending);
        $this->assertSame(3, $info->numAckPending);
        $this->assertInstanceOf(SequenceInfo::class, $info->delivered);
        $this->assertSame(4, $info->delivered->consumerSeq);
        $this->assertSame(10, $info->delivered->streamSeq);
        $this->assertSame(7, $info->ackFloor->streamSeq);
    }

    public function testBackoffSerializesToNanos(): void
    {
        $config = new ConsumerConfig(
            ackWait: 10.0,
            maxDeliver: 5,
            backoff: [10.0, 30.0, 120.0],
        );

        $arr = $config->toArray();
        $this->assertSame([10_000_000_000, 30_000_000_000, 120_000_000_000], $arr['backoff']);
        $this->assertSame(10_000_000_000, $arr['ack_wait']);
    }

    public function testBackoffOmittedWhenUnset(): void
    {
        $this->assertArrayNotHasKey('backoff', (new ConsumerConfig())->toArray());
    }

    public function testBackoffRoundTrip(): void
    {
        $config = ConsumerConfig::fromArray([
            'backoff' => [5_000_000_000, 60_000_000_000],
        ]);

        $this->assertSame([5.0, 60.0], $config->backoff);
    }

    public function testStreamMessageDecodesBase64Payload(): void
    {
        $msg = StreamMessage::fromArray([
            'subject' => 'foo.bar',
            'seq' => 42,
            'data' => base64_encode('the-payload'),
        ]);

        $this->assertSame('foo.bar', $msg->subject);
        $this->assertSame(42, $msg->sequence);
        $this->assertSame('the-payload', $msg->data);
    }

    public function testMessageSchedulesAreSentOnlyWhenAllowed(): void
    {
        // Absent rather than false: a server before 2.12 refuses a config naming a
        // field it does not know, so a stream that does not schedule must not say so.
        $this->assertArrayNotHasKey('allow_msg_schedules', (new StreamConfig(name: 'S'))->toArray());

        $config = new StreamConfig(name: 'S', allowMsgSchedules: true);
        $this->assertTrue($config->toArray()['allow_msg_schedules']);
        $this->assertTrue(StreamConfig::fromArray($config->toArray())->allowMsgSchedules);
    }

    public function testADurableSourceKeepsItsConsumerAndTransforms(): void
    {
        $config = StreamConfig::fromArray(new StreamConfig(
            name: 'WORK',
            sources: [new StreamSource(
                name: 'ORIGIN',
                subjectTransforms: [new SubjectTransform('o.due', 'w.normal')],
                consumer: new StreamConsumerSource('source', 'o.sourcing'),
            )],
        )->toArray());

        $source = $config->sources[0] ?? null;
        $this->assertInstanceOf(StreamSource::class, $source);
        $this->assertInstanceOf(StreamConsumerSource::class, $source->consumer);
        $this->assertSame('source', $source->consumer->name);
        $this->assertSame('o.sourcing', $source->consumer->deliverSubject);
        $this->assertSame('w.normal', $source->subjectTransforms[0]->destination ?? null);
    }

    public function testAFlowControlAckPolicyIsReadBackAsItself(): void
    {
        // Read back as Explicit, a durable source's consumer would be rewritten into
        // one the server refuses to source through.
        $this->assertSame(AckPolicy::FlowControl, ConsumerConfig::fromArray(['ack_policy' => 'flow_control'])->ackPolicy);
    }
}
