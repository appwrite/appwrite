<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\Tests\Support\FakeTransport;
use Utopia\NATS\Tests\Support\LateReadTransport;

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
            transportFactory: fn (string $scheme): FakeTransport => $fake,
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
        // just inside the client timeout -- see
        // ConsumerFetchLifecycleTest::testServerPullExpiryLandsBeforeTheClientDeadline.
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

    /**
     * The server counts a batch as delivered when it sends it, so a caller that
     * reads the reply after its timeout still has to get all of it. Whatever it
     * leaves on the socket stays unacknowledged until ackWait and is then charged
     * an attempt it never had. How late the read is must not matter.
     */
    #[DataProvider('lateness')]
    public function testABatchReadAfterTheTimeoutIsReturnedWhole(float $delay): void
    {
        $fake = new FakeTransport();
        $late = new LateReadTransport($fake);
        $conn = Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            transportFactory: fn (string $scheme): LateReadTransport => $late,
        ));
        $consumer = $this->consumer($conn);

        $sid = $this->pendingSid($conn);
        for ($i = 0; $i < 3; $i++) {
            $fake->pushInbound("MSG _INBOX.scripted {$sid} 2\r\nm{$i}\r\n");
        }
        $late->delayNextRead($delay);

        $batch = $consumer->fetch(3, 0.02, true);

        $this->assertCount(3, $batch, 'Every message the server sent for this pull request');
    }

    /** @return array<string, array{float}> */
    public static function lateness(): array
    {
        return [
            'just past the timeout' => [0.05],
            'long past the timeout' => [1.2],
        ];
    }

    /**
     * Reading past the timeout is for a reply already sent, not a reason to wait:
     * a partial no_wait reply still ends at its 404.
     */
    public function testAPartialNoWaitReplyEndsAtItsStatus(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake);
        $consumer = $this->consumer($conn);

        $sid = $this->pendingSid($conn);
        $fake->pushInbound("MSG _INBOX.scripted {$sid} 2\r\nm0\r\n");
        $fake->pushInbound("MSG _INBOX.scripted {$sid} 2\r\nm1\r\n");
        $this->pushStatus($fake, $sid, '404 No Messages');

        $started = microtime(true);
        $batch = $consumer->fetch(5, 2.0, true);
        $elapsed = microtime(true) - $started;

        $this->assertCount(2, $batch);
        $this->assertLessThan(0.5, $elapsed, 'The 404 ends the fetch, not the timeout');
    }

    /**
     * The other way a reply is late: still in transit when the timeout passes.
     * A no_wait request is always answered, so whatever arrives after the timeout
     * is that answer, already counted as delivered.
     */
    public function testANoWaitReplyStillArrivingAtTheTimeoutIsReadToItsEnd(): void
    {
        $fake = new FakeTransport();
        $late = new LateReadTransport($fake);
        $conn = Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            transportFactory: fn (string $scheme): LateReadTransport => $late,
        ));
        $consumer = $this->consumer($conn);

        $sid = $this->pendingSid($conn);
        for ($i = 0; $i < 3; $i++) {
            $fake->pushInbound("MSG _INBOX.scripted {$sid} 2\r\nm{$i}\r\n");
        }
        $late->arriveAfter(0.3);

        $batch = $consumer->fetch(3, 0.02, true);

        $this->assertCount(3, $batch, 'Every message the server sent for this pull request');
    }

    /**
     * A pull without an expiry may be held open by the server, so past the
     * timeout only frames already here are read: anything later is new work
     * the caller did not wait for.
     */
    public function testAPullWithoutAnExpiryDoesNotWaitForWorkPastItsTimeout(): void
    {
        $fake = new FakeTransport();
        $late = new LateReadTransport($fake);
        $conn = Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: false,
            transportFactory: fn (string $scheme): LateReadTransport => $late,
        ));
        $consumer = $this->consumer($conn);

        $fake->pushInbound("MSG _INBOX.scripted {$this->pendingSid($conn)} 2\r\nm0\r\n");
        $late->arriveAfter(0.5);

        $started = microtime(true);
        $batch = $consumer->fetch(1, 0.0);
        $elapsed = microtime(true) - $started;

        $this->assertCount(0, $batch);
        $this->assertLessThan(0.3, $elapsed, 'A zero timeout must not wait for work');
    }

    public function testAFetchTheServerNeverAnswersGivesUpOneWaitPastItsTimeout(): void
    {
        $fake = new FakeTransport();
        $consumer = $this->consumer($this->connect($fake));

        $started = microtime(true);
        $batch = $consumer->fetch(1, 0.05, true);
        $elapsed = microtime(true) - $started;

        $this->assertCount(0, $batch);
        $this->assertLessThan(0.05 + 1.0 + 0.3, $elapsed, 'A lost reply costs the timeout plus one reply wait');
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
