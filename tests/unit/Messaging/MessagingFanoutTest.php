<?php

namespace Tests\Unit\Messaging;

use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\Platform\Workers\Messaging;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;
use Utopia\Storage\Device\Local;

use function Swoole\Coroutine\run;

require_once __DIR__ . '/SwooleCoroutinePolyfill.php';

/**
 * Email adapter double that records peak in-flight {@see process()} calls and applies a configurable
 * per-recipient failure predicate. It cooperatively yields inside the send so fibers scheduled by the
 * polyfilled (or real) `batch()` interleave, making concurrent sends observable.
 */
class ConcurrencyProbeAdapter extends EmailAdapter
{
    public int $peakConcurrency = 0;

    public int $sendCalls = 0;

    private int $inFlight = 0;

    /**
     * @param int $maxPerRequest
     * @param (callable(string): bool)|null $failureFor Returns true when the given recipient should fail.
     */
    public function __construct(
        private readonly int $maxPerRequest = 1000,
        private $failureFor = null
    ) {
    }

    public function getName(): string
    {
        return 'ConcurrencyProbe';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return $this->maxPerRequest;
    }

    /**
     * @param EmailMessage $message
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    protected function process(EmailMessage $message): array
    {
        $this->sendCalls++;
        $this->inFlight++;
        $this->peakConcurrency = \max($this->peakConcurrency, $this->inFlight);

        // Yield so other coroutines run while this "request" is in flight.
        \Swoole\Coroutine::sleep(0.001);

        $response = new Response($this->getType());
        $delivered = 0;

        foreach ($message->getTo() as $recipient) {
            // Email recipients are normalized to ['email' => ..., 'name' => ...] arrays by the message.
            $email = $recipient['email'];
            $fails = \is_callable($this->failureFor) && ($this->failureFor)($email);

            if ($fails) {
                $response->addResult($email, 'Simulated delivery failure');
            } else {
                $delivered++;
                $response->addResult($email);
            }
        }

        $response->setDeliveredTo($delivered);

        $this->inFlight--;

        return $response->toArray();
    }
}

/**
 * Worker subclass that injects the instrumented email adapter instead of a network-backed provider adapter.
 * Retry backoff is zeroed so the throttle/retry tests stay instant.
 */
class TestableMessaging extends Messaging
{
    public function __construct(private readonly EmailAdapter $emailAdapter)
    {
    }

    protected function getEmailAdapter(Document $provider): ?EmailAdapter
    {
        return $this->emailAdapter;
    }

    protected function retryDelay(): float
    {
        return 0.0;
    }
}

/**
 * Email adapter double whose per-attempt outcome is scripted. Each {@see process()} call records the exact
 * `to` recipients it received and consults the next scripted step, which either throws (simulating a
 * connection-level provider error) or returns a per-recipient success/failure verdict. When the script runs
 * out, every recipient succeeds — modelling a provider that has stopped throttling.
 */
class ScriptedEmailAdapter extends EmailAdapter
{
    public int $sendCalls = 0;

    /**
     * @var array<array<string>> Recipient email lists captured per {@see process()} call, in call order.
     */
    public array $recipientsPerCall = [];

    /**
     * @param array<\Throwable|(callable(string): ?string)> $script One step per attempt. A Throwable is thrown
     *        for that attempt; a callable returns an error string for a failing recipient or null for success.
     */
    public function __construct(private readonly array $script = [])
    {
    }

    public function getName(): string
    {
        return 'ScriptedEmail';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    /**
     * @param EmailMessage $message
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    protected function process(EmailMessage $message): array
    {
        $recipients = \array_map(static fn (array $entry): string => $entry['email'], $message->getTo());
        $this->recipientsPerCall[] = $recipients;

        $step = $this->script[$this->sendCalls] ?? null;
        $this->sendCalls++;

        if ($step instanceof \Throwable) {
            throw $step;
        }

        $response = new Response($this->getType());
        $delivered = 0;

        foreach ($recipients as $recipient) {
            $error = \is_callable($step) ? $step($recipient) : null;

            if ($error === null) {
                $delivered++;
                $response->addResult($recipient);
            } else {
                $response->addResult($recipient, $error);
            }
        }

        $response->setDeliveredTo($delivered);

        return $response->toArray();
    }
}

/**
 * In-memory {@see Database} double. Serves subscribers/targets through cursor pagination and records every
 * query issued so the test can assert the streaming contract (cursorAfter + bounded page size, and that the
 * 1M-row subQueryTopicTargets filter is never used).
 */
class RecordingDatabase extends Database
{
    /**
     * @var array<array{collection: string, queries: array<Query>}>
     */
    public array $findCalls = [];

    public ?Document $updatedMessage = null;

    private readonly Authorization $authorization;

    /**
     * @param array<Document> $subscribers Subscriber rows (carry $sequence + targetInternalId + providerType).
     * @param array<string, Document> $targets Target rows keyed by $sequence.
     * @param array<Document> $directTargets Targets addressed directly by id or userId.
     * @param Document $provider The single enabled provider.
     */
    public function __construct(
        private readonly array $subscribers = [],
        private readonly array $targets = [],
        private readonly array $directTargets = [],
        private readonly ?Document $provider = null
    ) {
        $this->authorization = new Authorization();
    }

    public function getAuthorization(): Authorization
    {
        return $this->authorization;
    }

    public function skipValidation(callable $callback): mixed
    {
        return $callback();
    }

    public function findOne(string $collection, array $queries = []): Document
    {
        if ($collection === 'providers') {
            return $this->provider ?? new Document();
        }

        return new Document();
    }

    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        return new Document();
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        if ($collection === 'messages') {
            $this->updatedMessage = $document;
        }

        return $document;
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        $this->findCalls[] = ['collection' => $collection, 'queries' => $queries];

        return match ($collection) {
            'topics' => [new Document(['$id' => 'topic1', '$sequence' => '100'])],
            'subscribers' => $this->paginate($this->subscribers, $queries),
            'targets' => $this->resolveTargets($queries),
            default => [],
        };
    }

    /**
     * @param array<Document> $rows
     * @param array<Query> $queries
     * @return array<Document>
     */
    private function paginate(array $rows, array $queries): array
    {
        $limit = $this->limitOf($queries);
        $cursor = $this->cursorOf($queries);

        if ($cursor !== null) {
            $rows = \array_values(\array_filter(
                $rows,
                fn (Document $row) => $row->getSequence() > $cursor->getSequence()
            ));
        }

        return \array_slice($rows, 0, $limit);
    }

    /**
     * Either resolve a subscriber page's targets by $sequence, or paginate direct/user targets.
     *
     * @param array<Query> $queries
     * @return array<Document>
     */
    private function resolveTargets(array $queries): array
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_EQUAL && $query->getAttribute() === '$sequence') {
                $sequences = $query->getValues();

                return \array_values(\array_map(
                    fn ($sequence) => $this->targets[$sequence],
                    \array_filter($sequences, fn ($sequence) => isset($this->targets[$sequence]))
                ));
            }
        }

        return $this->paginate($this->directTargets, $queries);
    }

    /**
     * @param array<Query> $queries
     */
    private function limitOf(array $queries): int
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_LIMIT) {
                return (int) ($query->getValues()[0] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param array<Query> $queries
     */
    private function cursorOf(array $queries): ?Document
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_CURSOR_AFTER) {
                $value = $query->getValues()[0] ?? null;

                return $value instanceof Document ? $value : null;
            }
        }

        return null;
    }
}

/**
 * {@see RecordingDatabase} variant whose expired-device-token cleanup always fails: `findOne('targets')`
 * resolves to a real target so the worker attempts the update, and `updateDocument('targets')` throws a
 * transient error. Used to prove that a DB hiccup during best-effort cleanup never leaks into delivery
 * accounting or the retry decision.
 */
class ExpiredTokenCleanupFailingDatabase extends RecordingDatabase
{
    public int $targetUpdateAttempts = 0;

    public function findOne(string $collection, array $queries = []): Document
    {
        if ($collection === 'targets') {
            return new Document(['$id' => 'target-expired', '$sequence' => '1']);
        }

        return parent::findOne($collection, $queries);
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        if ($collection === 'targets') {
            $this->targetUpdateAttempts++;

            throw new \RuntimeException('Transient database error during target cleanup');
        }

        return parent::updateDocument($collection, $id, $document);
    }
}

class MessagingFanoutTest extends TestCase
{
    private const RECIPIENT_COUNT = 4500;

    private function provider(): Document
    {
        return new Document([
            '$id' => 'provider1',
            '$sequence' => '1',
            'enabled' => true,
            'type' => MESSAGE_TYPE_EMAIL,
            'provider' => 'mock',
            'name' => 'Mock Email',
            'credentials' => [],
            'options' => [
                'fromName' => 'Appwrite',
                'fromEmail' => 'noreply@example.com',
            ],
        ]);
    }

    private function message(): Document
    {
        return new Document([
            '$id' => 'message1',
            '$sequence' => '1',
            'providerType' => MESSAGE_TYPE_EMAIL,
            'topics' => ['topic1'],
            'targets' => [],
            'users' => [],
            'status' => MessageStatus::PROCESSING,
            'search' => 'message1',
            'data' => [
                'subject' => 'Hello',
                'content' => 'World',
                'html' => false,
            ],
        ]);
    }

    /**
     * Build a topic-backed dataset: RECIPIENT_COUNT subscribers, each pointing at one email target.
     *
     * @return array{subscribers: array<Document>, targets: array<string, Document>}
     */
    private function topicDataset(int $count): array
    {
        $subscribers = [];
        $targets = [];

        for ($i = 1; $i <= $count; $i++) {
            $sequence = (string) $i;
            $subscribers[] = new Document([
                '$id' => 'sub' . $i,
                '$sequence' => $sequence,
                'topicInternalId' => '100',
                'targetInternalId' => $sequence,
                'providerType' => MESSAGE_TYPE_EMAIL,
            ]);
            $targets[$sequence] = new Document([
                '$id' => 'target' . $i,
                '$sequence' => $sequence,
                'providerId' => null,
                'identifier' => "user{$i}@example.com",
                'providerType' => MESSAGE_TYPE_EMAIL,
            ]);
        }

        return ['subscribers' => $subscribers, 'targets' => $targets];
    }

    private function sendTopic(
        RecordingDatabase $database,
        EmailAdapter $adapter
    ): void {
        $worker = new TestableMessaging($adapter);
        // No setAccessible(true): reflection invokes private methods without it on PHP 8.1+, and the call is
        // deprecated on PHP 8.5 (the CI unit-test runtime), where the suite fails on triggered deprecations.
        $method = new \ReflectionMethod(Messaging::class, 'sendExternalMessage');

        $publisher = $this->createMock(UsagePublisher::class);
        $publisher->method('enqueue')->willReturn(true);

        $device = new Local(\sys_get_temp_dir());
        $project = new Document(['$id' => 'project1', '$sequence' => '1']);

        run(function () use ($worker, $method, $database, $device, $project, $publisher) {
            $method->invoke($worker, $database, $this->message(), $device, $project, $publisher);
        });
    }

    public function testRecipientsArePaginatedWithoutSubqueryFilter(): void
    {
        $data = $this->topicDataset(self::RECIPIENT_COUNT);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());
        $adapter = new ConcurrencyProbeAdapter();

        $this->sendTopic($database, $adapter);

        $topicReads = \array_filter($database->findCalls, fn ($call) => $call['collection'] === 'topics');
        $subscriberReads = \array_values(\array_filter($database->findCalls, fn ($call) => $call['collection'] === 'subscribers'));

        // The topic's `targets` attribute (the subQueryTopicTargets filter) is never materialized: topics are
        // only read with a $sequence projection, and recipients come from the subscribers collection instead.
        $this->assertNotEmpty($topicReads);
        foreach ($topicReads as $call) {
            $selected = $this->selectFields($call['queries']);
            $this->assertNotContains('targets', $selected, 'Topic read must not select the targets subquery attribute');
        }

        // Subscribers are walked in bounded pages via cursorAfter. 4500 recipients over a 1000 page size is
        // five pages; every page after the first carries a cursor and none exceeds the page size.
        $expectedPages = (int) \ceil(self::RECIPIENT_COUNT / MESSAGE_RECIPIENTS_PAGE_SIZE);
        $this->assertCount($expectedPages, $subscriberReads);

        foreach ($subscriberReads as $index => $call) {
            $this->assertSame(MESSAGE_RECIPIENTS_PAGE_SIZE, $this->limitOf($call['queries']));

            if ($index === 0) {
                $this->assertFalse($this->hasCursor($call['queries']), 'First page must not carry a cursor');
            } else {
                $this->assertTrue($this->hasCursor($call['queries']), 'Subsequent pages must paginate with cursorAfter');
            }
        }
    }

    public function testConcurrentSendsNeverExceedConfiguredLimit(): void
    {
        // One recipient per batch (maxPerRequest = 1) maximises the number of concurrent in-flight sends so
        // the Semaphore's bound is actually exercised: 1500 batches contend for MESSAGE_SEND_CONCURRENCY permits.
        $count = 1500;
        $data = $this->topicDataset($count);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());
        $adapter = new ConcurrencyProbeAdapter(maxPerRequest: 1);

        $this->sendTopic($database, $adapter);

        $this->assertSame($count, $adapter->sendCalls, 'Every recipient is sent exactly once');
        $this->assertGreaterThan(1, $adapter->peakConcurrency, 'Sends should actually run concurrently');
        $this->assertLessThanOrEqual(
            MESSAGE_SEND_CONCURRENCY,
            $adapter->peakConcurrency,
            'Concurrent sends must never exceed MESSAGE_SEND_CONCURRENCY'
        );
    }

    public function testDeliveryErrorsAreCappedWhileFailedCountIsComplete(): void
    {
        $count = self::RECIPIENT_COUNT;
        $data = $this->topicDataset($count);
        // Every recipient fails: 4500 individual failures, but only MESSAGE_DELIVERY_ERRORS_LIMIT are retained.
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());
        $adapter = new ConcurrencyProbeAdapter(failureFor: fn (string $recipient) => true);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        $errors = $message->getAttribute('deliveryErrors');
        $this->assertCount(MESSAGE_DELIVERY_ERRORS_LIMIT, $errors, 'Delivery errors must be capped');

        // Failed recipients are fully accounted for even though only 100 error strings are kept: every one of
        // the 4500 recipients failed, so deliveredTotal is zero and the message is marked FAILED.
        $this->assertSame(0, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::FAILED, $message->getAttribute('status'));
    }

    public function testDeliveredTotalAndStatusReflectPartialSuccess(): void
    {
        $count = self::RECIPIENT_COUNT;
        $data = $this->topicDataset($count);
        // Fail exactly the recipients whose local-part index is divisible by 10.
        $failing = static function (string $recipient): bool {
            \preg_match('/user(\d+)@/', $recipient, $matches);

            return isset($matches[1]) && ((int) $matches[1]) % 10 === 0;
        };
        $expectedFailures = \intdiv($count, 10);

        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());
        $adapter = new ConcurrencyProbeAdapter(failureFor: $failing);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        // deliveredTotal is exact across all pages; failures partition the remainder exactly.
        $this->assertSame($count - $expectedFailures, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::FAILED, $message->getAttribute('status'));
        $this->assertCount(MESSAGE_DELIVERY_ERRORS_LIMIT, $message->getAttribute('deliveryErrors'));
    }

    public function testFullSuccessMarksMessageSent(): void
    {
        $data = $this->topicDataset(self::RECIPIENT_COUNT);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());
        $adapter = new ConcurrencyProbeAdapter();

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);
        $this->assertSame(self::RECIPIENT_COUNT, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::SENT, $message->getAttribute('status'));
        $this->assertEmpty($message->getAttribute('deliveryErrors'));
    }

    public function testTerminalStatusIsSkipped(): void
    {
        $data = $this->topicDataset(10);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());
        $adapter = new ConcurrencyProbeAdapter();

        $worker = new TestableMessaging($adapter);
        // No setAccessible(true): reflection invokes private methods without it on PHP 8.1+, and the call is
        // deprecated on PHP 8.5 (the CI unit-test runtime), where the suite fails on triggered deprecations.
        $method = new \ReflectionMethod(Messaging::class, 'sendExternalMessage');
        $publisher = $this->createMock(UsagePublisher::class);
        $device = new Local(\sys_get_temp_dir());
        $project = new Document(['$id' => 'project1', '$sequence' => '1']);

        $message = $this->message();
        $message->setAttribute('status', MessageStatus::SENT);

        run(function () use ($worker, $method, $database, $device, $project, $publisher, $message) {
            $method->invoke($worker, $database, $message, $device, $project, $publisher);
        });

        $this->assertSame(0, $adapter->sendCalls, 'Already-sent messages must not be reprocessed');
        $this->assertNull($database->updatedMessage, 'Terminal messages must not be rewritten');
    }

    public function testThrottledBatchIsRetriedThenFullyDelivered(): void
    {
        $count = 5;
        $data = $this->topicDataset($count);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());

        // First attempt throttles every recipient; the script then runs out so the second attempt succeeds.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): string => 'Rate limit exceeded, please retry',
        ]);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        // The batch was retried exactly once and then fully delivered with no double-counting.
        $this->assertSame(2, $adapter->sendCalls, 'Throttled batch must be retried once');
        $this->assertSame($count, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::SENT, $message->getAttribute('status'));
        $this->assertEmpty($message->getAttribute('deliveryErrors'));

        // Both attempts targeted the full recipient set (none had succeeded on the first attempt).
        $this->assertCount($count, $adapter->recipientsPerCall[0]);
        $this->assertCount($count, $adapter->recipientsPerCall[1]);
    }

    public function testRetryTargetsOnlyThrottledRecipients(): void
    {
        $count = 5;
        $data = $this->topicDataset($count);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());

        // user2@ and user4@ throttle on the first attempt; everyone else succeeds. The second attempt has no
        // script step, so the two retried recipients then succeed.
        $throttled = ['user2@example.com', 'user4@example.com'];
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => \in_array($recipient, $throttled, true)
                ? 'Too Many Requests'
                : null,
        ]);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        $this->assertSame(2, $adapter->sendCalls, 'A partially throttled batch must be retried once');

        // The retry must re-send to ONLY the throttled recipients — never the ones already delivered.
        \sort($adapter->recipientsPerCall[1]);
        $this->assertSame($throttled, $adapter->recipientsPerCall[1]);

        // Every recipient is ultimately delivered and counted exactly once.
        $this->assertSame($count, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::SENT, $message->getAttribute('status'));
        $this->assertEmpty($message->getAttribute('deliveryErrors'));
    }

    public function testNonRetryableFailureIsNotRetried(): void
    {
        $count = 3;
        $data = $this->topicDataset($count);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());

        // user1@ permanently fails with a non-retryable error; the rest succeed.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => $recipient === 'user1@example.com'
                ? 'Invalid recipient address'
                : null,
        ]);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        // A permanent failure is recorded immediately, never retried.
        $this->assertSame(1, $adapter->sendCalls, 'Non-retryable failures must not trigger a retry');
        $this->assertSame($count - 1, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::FAILED, $message->getAttribute('status'));
        $errors = $message->getAttribute('deliveryErrors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('user1@example.com', $errors[0]);
        $this->assertStringContainsString('Invalid recipient address', $errors[0]);
    }

    public function testExhaustedRetriesRecordTerminalFailure(): void
    {
        $count = 2;
        $data = $this->topicDataset($count);
        $database = new RecordingDatabase($data['subscribers'], $data['targets'], [], $this->provider());

        // Every attempt throttles, for more steps than MESSAGE_SEND_MAX_RETRIES, so retries are exhausted.
        $script = \array_fill(
            0,
            MESSAGE_SEND_MAX_RETRIES + 2,
            static fn (string $recipient): string => 'Service unavailable, throttled'
        );
        $adapter = new ScriptedEmailAdapter($script);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        // The batch is attempted exactly MESSAGE_SEND_MAX_RETRIES times, then the still-throttled recipients
        // become terminal failures with nothing delivered.
        $this->assertSame(MESSAGE_SEND_MAX_RETRIES, $adapter->sendCalls, 'Attempts must be capped at MESSAGE_SEND_MAX_RETRIES');
        $this->assertSame(0, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::FAILED, $message->getAttribute('status'));
        $this->assertCount($count, $message->getAttribute('deliveryErrors'));
    }

    public function testExpiredTokenCleanupFailureDoesNotCorruptAccounting(): void
    {
        $count = 3;
        $data = $this->topicDataset($count);
        // The cleanup path always throws, but the worker must isolate it: the send result is what's accounted.
        $database = new ExpiredTokenCleanupFailingDatabase($data['subscribers'], $data['targets'], [], $this->provider());

        // user1@ comes back with an expired device token (triggering cleanup, which then throws); the other
        // two recipients are delivered. The expired-token error is terminal, never retryable.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => $recipient === 'user1@example.com'
                ? 'Expired device token'
                : null,
        ]);

        $this->sendTopic($database, $adapter);

        $message = $database->updatedMessage;
        $this->assertNotNull($message);

        // The worker reached the cleanup (so the isolation is genuinely exercised) and it threw.
        $this->assertGreaterThan(0, $database->targetUpdateAttempts, 'Expired-token cleanup must have been attempted');

        // The send was attempted exactly once: a thrown cleanup must not look like a transient send failure
        // and must not trigger a retry.
        $this->assertSame(1, $adapter->sendCalls, 'Cleanup failure must not trigger a send retry');

        // Accounting reflects the send result, not the cleanup throw: two delivered, one terminal failure.
        $this->assertSame($count - 1, $message->getAttribute('deliveredTotal'));
        $this->assertSame(MessageStatus::FAILED, $message->getAttribute('status'));

        // The recorded error is the provider's expired-token failure for the real recipient — proving the
        // database exception was swallowed rather than misattributed as the send error.
        $errors = $message->getAttribute('deliveryErrors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('user1@example.com', $errors[0]);
        $this->assertStringContainsString('Expired device token', $errors[0]);
        $this->assertStringNotContainsString('Transient database error', $errors[0]);
    }

    /**
     * @param array<Query> $queries
     * @return array<string>
     */
    private function selectFields(array $queries): array
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                return $query->getValues();
            }
        }

        return [];
    }

    /**
     * @param array<Query> $queries
     */
    private function limitOf(array $queries): int
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_LIMIT) {
                return (int) ($query->getValues()[0] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param array<Query> $queries
     */
    private function hasCursor(array $queries): bool
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_CURSOR_AFTER) {
                return true;
            }
        }

        return false;
    }
}
