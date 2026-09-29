<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * What Consumer::fetch() puts on the wire, and what it accepts back.
 *
 * Both halves are load-bearing and neither shows up as an error. A pull request
 * that contradicts itself still returns messages, only slowly; a status frame
 * mistaken for data still returns an object, only an empty one that fails in the
 * caller rather than here.
 */
final class ConsumerPullRequestTest extends TestCase
{
    private function connect(FakeTransport $fake): Connection
    {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            transportFactory: fn(string $scheme): FakeTransport => $fake,
        ));
    }

    private function consumer(Connection $conn): Consumer
    {
        return new Consumer($conn, 'STREAM', ConsumerInfo::fromArray([
            'stream_name' => 'STREAM',
            'name' => 'durable',
        ]));
    }

    /**
     * The JSON body of the pull request the client published.
     *
     * @return array<string, mixed>
     */
    private function pullRequest(FakeTransport $fake): array
    {
        $matched = preg_match(
            '#PUB \$JS\.API\.CONSUMER\.MSG\.NEXT\.STREAM\.durable \S+ \d+\r\n(\{.*?\})\r\n#s',
            $fake->written,
            $m,
        );
        $this->assertSame(1, $matched, 'No pull request was published');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The sid fetch() is about to give its inbox subscription.
     *
     * Inbound frames are routed by sid, and a reply has to be queued on the fake
     * before the synchronous fetch() starts reading -- by which point the inbox
     * subject it generated is not observable yet. The sid is, because it comes
     * from a counter.
     */
    private function pendingSid(Connection $conn): string
    {
        return (string) (new \ReflectionProperty(Connection::class, 'nextSid'))->getValue($conn);
    }

    /** Queue a status frame on the inbox fetch() is about to open. */
    private function pushStatus(FakeTransport $fake, string $sid, string $status, string $replyTo = ''): void
    {
        $block = "NATS/1.0 {$status}\r\n\r\n";
        $length = \strlen($block);
        $reply = $replyTo === '' ? '' : " {$replyTo}";

        $fake->pushInbound("HMSG _INBOX.scripted {$sid}{$reply} {$length} {$length}\r\n{$block}\r\n");
    }

    public function testNoWaitPullRequestCarriesNoExpiry(): void
    {
        // The server honours the expiry over no_wait: given both, it waits out
        // the window and answers 408 instead of answering 404 at once, which
        // makes a no_wait poll cost the full timeout it was meant to avoid.
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $consumer->fetch(1, 0.02, true);

        $request = $this->pullRequest($fake);

        $this->assertTrue($request['no_wait'] ?? null);
        $this->assertArrayNotHasKey('expires', $request);
    }

    public function testWaitingPullRequestStillCarriesAnExpiry(): void
    {
        // The other half of the same decision: without no_wait the server needs
        // the expiry, or it holds the request for its own default. It lands
        // just inside the client deadline -- see
        // ConsumerFetchLifecycleTest::testServerPullExpiryLandsBeforeTheClientDeadline
        // for why the two must not share a boundary.
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $consumer->fetch(1, 0.02);

        $request = $this->pullRequest($fake);

        $this->assertArrayNotHasKey('no_wait', $request);
        $this->assertSame(18_000_000, $request['expires']);
    }

    public function testBatchSizeAndMaxBytesSurviveTheNoWaitPath(): void
    {
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $consumer->fetch(7, 0.02, true, 4096);

        $request = $this->pullRequest($fake);

        $this->assertSame(7, $request['batch']);
        $this->assertSame(4096, $request['max_bytes']);
        $this->assertTrue($request['no_wait'] ?? null);
        $this->assertArrayNotHasKey('expires', $request);
    }

    /**
     * A 503 is what the server sends when the consumer's API subject has no
     * responder at that instant -- a consumer still being created, or a raft
     * leader moving. It was not among the codes fetch() named, so it fell
     * through and was returned as a message with an empty body.
     */
    public function testNoRespondersStatusIsNotReturnedAsAMessage(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $this->pushStatus($fake, $this->pendingSid($conn), '503 No Responders');

        $batch = $consumer->fetch(1, 0.02, true);

        $this->assertCount(0, $batch, 'A 503 status frame is not a message');
    }

    /**
     * The same for a status nobody has enumerated. The rule has to be "a status
     * frame is not data", not a longer list of numbers, or the next code the
     * server adds is returned as a job all over again.
     */
    public function testAnUnrecognisedStatusIsNotReturnedAsAMessage(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $this->pushStatus($fake, $this->pendingSid($conn), '599 Something New');

        $batch = $consumer->fetch(1, 0.02, true);

        $this->assertCount(0, $batch);
    }

    public function testNoMessagesStatusEndsTheFetchWithoutAMessage(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $this->pushStatus($fake, $this->pendingSid($conn), '404 No Messages');

        $batch = $consumer->fetch(1, 0.02, true);

        $this->assertCount(0, $batch);
    }

    /**
     * 100 is the one status that means carry on: it is a keep-alive, so it must
     * not end the fetch, and a real message behind it still has to arrive.
     */
    public function testFlowControlStatusIsAcknowledgedAndDoesNotEndTheFetch(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $sid = $this->pendingSid($conn);
        $this->pushStatus($fake, $sid, '100 FlowControl Request', '_FC.reply');
        $fake->pushInbound("MSG _INBOX.scripted {$sid} 5\r\nhello\r\n");

        $batch = $consumer->fetch(1, 0.5, true);

        $this->assertCount(1, $batch, 'A keep-alive must not end the fetch');
        foreach ($batch as $message) {
            $this->assertSame('hello', $message->getData());
        }
        $this->assertStringContainsString('PUB _FC.reply', $fake->written, 'The keep-alive is answered');
    }

    public function testAMessageWithoutAStatusIsStillDelivered(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $fake->pushInbound("MSG _INBOX.scripted {$this->pendingSid($conn)} 7\r\npayload\r\n");

        $batch = $consumer->fetch(1, 0.5, true);

        $this->assertCount(1, $batch);
        foreach ($batch as $message) {
            $this->assertSame('payload', $message->getData());
        }
    }
}
