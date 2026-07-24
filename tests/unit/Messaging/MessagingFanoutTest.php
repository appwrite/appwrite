<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

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

/**
 * Pure unit tests for the messaging worker's fan-out building blocks: the retryable-error classifier, the
 * batch retry/backoff loop, and the cursor-paginated recipient stream. Each method is exercised directly
 * through reflection with no Swoole coroutines.
 *
 * The coroutine fan-out integration itself — the bounded-concurrency {@see \Utopia\Lock\Semaphore} driving
 * `Swoole\Coroutine\batch()` — is intentionally NOT covered here. Executing real Swoole coroutines inside the
 * shared unit process is unsafe: the in-process scheduler destabilises later, unrelated tests in the suite.
 * That integration is covered by the e2e Messaging suite instead. See PR #12465.
 *
 * Private methods are invoked via {@see \ReflectionMethod::invoke()} WITHOUT {@see \ReflectionMethod::setAccessible()}:
 * reflection invokes private methods directly on PHP 8.1+, and `setAccessible(true)` is deprecated on PHP 8.5
 * (the CI unit-test runtime), where the suite fails on triggered deprecations.
 */
final class MessagingFanoutTest extends TestCase
{
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
            'data' => [
                'subject' => 'Hello',
                'content' => 'World',
                'html' => false,
            ],
        ]);
    }

    /**
     * Invoke the worker's private {@see Messaging::retrySend()} for an email batch.
     *
     * @param array<string> $batch
     * @return array{delivered: int, errors: array<string>}
     */
    private function retrySend(array $batch, EmailAdapter $adapter, Database $database): array
    {
        $worker = new TestableMessaging();
        $method = new \ReflectionMethod(Messaging::class, 'retrySend');

        $device = new Local(\sys_get_temp_dir());
        $project = new Document(['$id' => 'project1', '$sequence' => '1']);

        /** @var array{delivered: int, errors: array<string>} $result */
        $result = $method->invoke(
            $worker,
            $batch,
            $this->message(),
            $this->provider(),
            MESSAGE_TYPE_EMAIL,
            $adapter,
            $database,
            $device,
            $project
        );

        return $result;
    }

    public function testIsRetryableErrorMatchesTransientFailures(): void
    {
        $worker = new TestableMessaging();
        $method = new \ReflectionMethod(Messaging::class, 'isRetryableError');

        $retryable = [
            'Throttling',
            'rate exceeded',
            'Rate limit reached',
            'Too Many Requests',
            '429 Too Many Requests',
            'quota exceeded',
            'Service Unavailable',
            '503 Service Unavailable',
            'Connection timed out',
            'Request timeout',
            'Service temporarily unavailable',
        ];

        foreach ($retryable as $error) {
            $this->assertTrue(
                $method->invoke($worker, $error),
                "Expected '{$error}' to be classified as retryable"
            );
        }

        $permanent = [
            'invalid recipient',
            'Mailbox does not exist',
            'Authentication failed',
            'Expired device token',
        ];

        foreach ($permanent as $error) {
            $this->assertFalse(
                $method->invoke($worker, $error),
                "Expected '{$error}' to be classified as permanent"
            );
        }

        $this->assertFalse($method->invoke($worker, null), 'A null error must never be retryable');
        $this->assertFalse($method->invoke($worker, ''), 'An empty error must never be retryable');
    }

    public function testThrottledBatchIsRetriedThenFullyDelivered(): void
    {
        $batch = ['user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com', 'user5@example.com'];

        // First attempt throttles every recipient; the script then runs out so the second attempt succeeds.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): string => 'Rate limit exceeded, please retry',
        ]);

        $result = $this->retrySend($batch, $adapter, new InMemoryDatabase());

        // Retried exactly once, then everyone delivered with no double-counting and no terminal errors.
        $this->assertSame(2, $adapter->sendCalls, 'Throttled batch must be retried once');
        $this->assertSame(\count($batch), $result['delivered']);
        $this->assertEmpty($result['errors']);

        // Both attempts targeted the full recipient set (none had succeeded on the first attempt).
        $this->assertSame($batch, $adapter->recipientsPerCall[0]);
        $this->assertSame($batch, $adapter->recipientsPerCall[1]);
    }

    public function testRetryTargetsOnlyThrottledRecipients(): void
    {
        $batch = ['user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com', 'user5@example.com'];

        // user2@ and user4@ throttle on the first attempt; everyone else succeeds. The second attempt has no
        // script step, so the two retried recipients then succeed.
        $throttled = ['user2@example.com', 'user4@example.com'];
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => \in_array($recipient, $throttled, true)
                ? 'Too Many Requests'
                : null,
        ]);

        $result = $this->retrySend($batch, $adapter, new InMemoryDatabase());

        $this->assertSame(2, $adapter->sendCalls, 'A partially throttled batch must be retried once');

        // The retry must re-send to EXACTLY the throttled recipients — never the ones already delivered.
        $this->assertSame($throttled, $adapter->recipientsPerCall[1]);

        // Every recipient is ultimately delivered and counted exactly once.
        $this->assertSame(\count($batch), $result['delivered']);
        $this->assertEmpty($result['errors']);
    }

    public function testNonRetryableFailureIsNotRetried(): void
    {
        $batch = ['user1@example.com', 'user2@example.com', 'user3@example.com'];

        // user1@ permanently fails with a non-retryable error; the rest succeed.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => $recipient === 'user1@example.com'
                ? 'Invalid recipient address'
                : null,
        ]);

        $result = $this->retrySend($batch, $adapter, new InMemoryDatabase());

        // A permanent failure is recorded immediately, never retried.
        $this->assertSame(1, $adapter->sendCalls, 'Non-retryable failures must not trigger a retry');
        $this->assertSame(\count($batch) - 1, $result['delivered']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('user1@example.com', $result['errors'][0]);
        $this->assertStringContainsString('Invalid recipient address', $result['errors'][0]);
    }

    public function testExhaustedRetriesRecordTerminalFailure(): void
    {
        $batch = ['user1@example.com', 'user2@example.com'];

        // Every attempt throttles, for more steps than MESSAGE_SEND_MAX_RETRIES, so retries are exhausted.
        $script = \array_fill(
            0,
            MESSAGE_SEND_MAX_RETRIES + 2,
            static fn (string $recipient): string => 'Service unavailable, throttled'
        );
        $adapter = new ScriptedEmailAdapter($script);

        $result = $this->retrySend($batch, $adapter, new InMemoryDatabase());

        // The batch is attempted exactly MESSAGE_SEND_MAX_RETRIES times, then the still-throttled recipients
        // become terminal failures with nothing delivered.
        $this->assertSame(MESSAGE_SEND_MAX_RETRIES, $adapter->sendCalls, 'Attempts must be capped at MESSAGE_SEND_MAX_RETRIES');
        $this->assertSame(0, $result['delivered']);
        $this->assertCount(\count($batch), $result['errors']);
    }

    public function testDeliveryErrorsAreCappedWhileDeliveredCountIsExact(): void
    {
        // More failing recipients than the retained-error cap, plus a known number of successes, so the
        // delivered tally stays exact even though the error list is truncated.
        $failingCount = MESSAGE_DELIVERY_ERRORS_LIMIT + 20;
        $succeedingCount = 100;

        $batch = [];
        for ($i = 1; $i <= $failingCount; $i++) {
            $batch[] = "fail{$i}@example.com";
        }
        for ($i = 1; $i <= $succeedingCount; $i++) {
            $batch[] = "ok{$i}@example.com";
        }

        // Permanent (non-retryable) failure for every "fail*" recipient; everyone else is delivered.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => \str_starts_with($recipient, 'fail')
                ? 'Invalid recipient address'
                : null,
        ]);

        $result = $this->retrySend($batch, $adapter, new InMemoryDatabase());

        // Errors are capped, but the delivered count reflects every success exactly.
        $this->assertCount(MESSAGE_DELIVERY_ERRORS_LIMIT, $result['errors'], 'Delivery errors must be capped');
        $this->assertSame($succeedingCount, $result['delivered'], 'Delivered count stays exact despite the error cap');
        $this->assertSame(1, $adapter->sendCalls, 'Non-retryable failures must not trigger a retry');
    }

    public function testExpiredTokenCleanupFailureDoesNotCorruptAccounting(): void
    {
        $batch = ['user1@example.com', 'user2@example.com', 'user3@example.com'];

        // The cleanup path always throws, but the worker must isolate it: the send result is what's accounted.
        $database = new ExpiredTokenCleanupFailingDatabase();

        // user1@ comes back with an expired device token (triggering cleanup, which then throws); the other
        // two recipients are delivered. The expired-token error is terminal, never retryable.
        $adapter = new ScriptedEmailAdapter([
            static fn (string $recipient): ?string => $recipient === 'user1@example.com'
                ? 'Expired device token'
                : null,
        ]);

        $result = $this->retrySend($batch, $adapter, $database);

        // The worker reached the cleanup (so the isolation is genuinely exercised) and it threw.
        $this->assertGreaterThan(0, $database->targetUpdateAttempts, 'Expired-token cleanup must have been attempted');

        // The send was attempted exactly once: a thrown cleanup must not look like a transient send failure
        // and must not trigger a retry.
        $this->assertSame(1, $adapter->sendCalls, 'Cleanup failure must not trigger a send retry');

        // Accounting reflects the send result, not the cleanup throw: two delivered, one terminal failure.
        $this->assertSame(\count($batch) - 1, $result['delivered']);

        // The recorded error is the provider's expired-token failure for the real recipient — proving the
        // database exception was swallowed rather than misattributed as the send error.
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('user1@example.com', $result['errors'][0]);
        $this->assertStringContainsString('Expired device token', $result['errors'][0]);
        $this->assertStringNotContainsString('Transient database error', $result['errors'][0]);
    }

    public function testRecipientsArePaginatedWithoutSubqueryFilter(): void
    {
        // Always span several pages regardless of the configured page size, so cursor pagination is exercised.
        $count = MESSAGE_RECIPIENTS_PAGE_SIZE * 2 + 500;
        $data = $this->topicDataset($count);
        $database = new RecordingDatabase($data['subscribers'], $data['targets']);

        $identifiers = $this->collectStream($database, topicIds: ['topic1']);

        $topicReads = \array_values(\array_filter($database->findCalls, fn ($call) => $call['collection'] === 'topics'));
        $subscriberReads = \array_values(\array_filter($database->findCalls, fn ($call) => $call['collection'] === 'subscribers'));

        // The topic's `targets` attribute (the subQueryTopicTargets filter) is never materialized: topics are
        // only read with a $sequence projection, and recipients come from the subscribers collection instead.
        $this->assertNotEmpty($topicReads);
        foreach ($topicReads as $call) {
            $this->assertNotContains('targets', $this->selectFields($call['queries']), 'Topic read must not select the targets subquery attribute');
        }

        // Subscribers are walked in bounded pages via cursorAfter; no query ever requests a runaway page size.
        $expectedPages = (int) \ceil($count / MESSAGE_RECIPIENTS_PAGE_SIZE);
        $this->assertCount($expectedPages, $subscriberReads);

        foreach ($subscriberReads as $index => $call) {
            $this->assertSame(MESSAGE_RECIPIENTS_PAGE_SIZE, $this->limitOf($call['queries']));
            $this->assertSame('100', $this->topicInternalIdOf($call['queries']), 'Subscribers must be filtered by the topic internal id');

            if ($index === 0) {
                $this->assertFalse($this->hasCursor($call['queries']), 'First page must not carry a cursor');
            } else {
                $this->assertTrue($this->hasCursor($call['queries']), 'Subsequent pages must paginate with cursorAfter');
            }
        }

        // No query in the whole stream issues a runaway (>= 1,000,000) limit.
        foreach ($database->findCalls as $call) {
            $this->assertLessThan(1_000_000, $this->limitOf($call['queries']), 'No query may request a million-row page');
        }

        // The duplicate identifier collapses: one row per distinct subscriber identifier resolved.
        $this->assertCount($count, $identifiers);
        for ($i = 1; $i <= $count; $i++) {
            $this->assertArrayHasKey("user{$i}@example.com", $identifiers);
        }
    }

    public function testStreamDeduplicatesIdentifiersWithinPage(): void
    {
        // Two subscribers in a single page resolve to targets sharing one identifier; the page must collapse
        // them to a single recipient entry.
        $subscribers = [
            new Document(['$id' => 'sub1', '$sequence' => '1', 'topicInternalId' => '100', 'targetInternalId' => '1', 'providerType' => MESSAGE_TYPE_EMAIL]),
            new Document(['$id' => 'sub2', '$sequence' => '2', 'topicInternalId' => '100', 'targetInternalId' => '2', 'providerType' => MESSAGE_TYPE_EMAIL]),
        ];
        $targets = [
            '1' => new Document(['$id' => 'target1', '$sequence' => '1', 'providerId' => null, 'identifier' => 'dup@example.com', 'providerType' => MESSAGE_TYPE_EMAIL]),
            '2' => new Document(['$id' => 'target2', '$sequence' => '2', 'providerId' => null, 'identifier' => 'dup@example.com', 'providerType' => MESSAGE_TYPE_EMAIL]),
        ];
        $database = new RecordingDatabase($subscribers, $targets);

        $pages = $this->collectPages($database, topicIds: ['topic1']);

        $this->assertCount(1, $pages, 'A single subscriber page yields a single grouped result');
        $providerId = $this->provider()->getId();
        $this->assertArrayHasKey($providerId, $pages[0]);
        $this->assertSame(['dup@example.com'], \array_keys($pages[0][$providerId]), 'Duplicate identifiers must collapse to one');
    }

    public function testStreamResolvesUsersAndDirectTargets(): void
    {
        $userTargets = [
            new Document(['$id' => 'utarget1', '$sequence' => '10', 'providerId' => null, 'identifier' => 'byuser@example.com', 'providerType' => MESSAGE_TYPE_EMAIL]),
        ];
        $directTargets = [
            new Document(['$id' => 'dtarget1', '$sequence' => '20', 'providerId' => null, 'identifier' => 'bytarget@example.com', 'providerType' => MESSAGE_TYPE_EMAIL]),
        ];
        $database = new RecordingDatabase([], [], $userTargets, $directTargets);

        $identifiers = $this->collectStream($database, userIds: ['user-a'], targetIds: ['target-b']);

        // No topics in play: only the user and direct-target queries run, and both identifiers resolve.
        $this->assertSame([], \array_values(\array_filter($database->findCalls, fn ($call) => $call['collection'] === 'topics')));
        $this->assertArrayHasKey('byuser@example.com', $identifiers);
        $this->assertArrayHasKey('bytarget@example.com', $identifiers);
        $this->assertCount(2, $identifiers);
    }

    /**
     * Build a topic-backed dataset: $count subscribers, each pointing at one email target. A single identifier
     * is duplicated so per-page dedup is exercised when the dataset fits in one page.
     *
     * @return array{subscribers: array<Document>, targets: array<int, Document>}
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

    /**
     * Drive {@see Messaging::streamRecipients()} and merge every yielded page into a single identifier map.
     *
     * @param array<string> $topicIds
     * @param array<string> $userIds
     * @param array<string> $targetIds
     * @return array<string, null>
     */
    private function collectStream(
        RecordingDatabase $database,
        array $topicIds = [],
        array $userIds = [],
        array $targetIds = []
    ): array {
        $identifiers = [];

        foreach ($this->collectPages($database, $topicIds, $userIds, $targetIds) as $page) {
            foreach ($page as $perProvider) {
                foreach ($perProvider as $identifier => $_) {
                    $identifiers[$identifier] = null;
                }
            }
        }

        return $identifiers;
    }

    /**
     * @param array<string> $topicIds
     * @param array<string> $userIds
     * @param array<string> $targetIds
     * @return array<array<string, array<string, null>>>
     */
    private function collectPages(
        RecordingDatabase $database,
        array $topicIds = [],
        array $userIds = [],
        array $targetIds = []
    ): array {
        $method = new \ReflectionMethod(Messaging::class, 'streamRecipients');
        $worker = new TestableMessaging();

        /** @var \Generator<array<string, array<string, null>>> $generator */
        $generator = $method->invoke(
            $worker,
            $database,
            $topicIds,
            $userIds,
            $targetIds,
            MESSAGE_TYPE_EMAIL,
            $this->provider()
        );

        return \iterator_to_array($generator, false);
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

    /**
     * @param array<Query> $queries
     */
    private function topicInternalIdOf(array $queries): ?string
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_EQUAL && $query->getAttribute() === 'topicInternalId') {
                return (string) ($query->getValues()[0] ?? null);
            }
        }

        return null;
    }
}

/**
 * Worker subclass that zeroes the retry backoff so the throttle/retry tests stay instant. The worker already
 * skips a non-positive delay, so no coroutine sleep is ever reached.
 */
class TestableMessaging extends Messaging
{
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
        parent::__construct();
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
 * Minimal in-memory {@see Database} double for the retry tests, which only build email messages with no
 * attachments and never touch the expired-token cleanup path. Every read resolves empty.
 */
class InMemoryDatabase extends Database
{
    private readonly Authorization $authorization;

    public function __construct()
    {
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
        return new Document();
    }

    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        return new Document();
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        return $document;
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        return [];
    }
}

/**
 * {@see InMemoryDatabase} variant whose expired-device-token cleanup always fails: `findOne('targets')`
 * resolves to a real target so the worker attempts the update, and `updateDocument('targets')` throws a
 * transient error. Used to prove that a DB hiccup during best-effort cleanup never leaks into delivery
 * accounting or the retry decision.
 */
class ExpiredTokenCleanupFailingDatabase extends InMemoryDatabase
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

/**
 * In-memory {@see Database} double for the streaming tests. Serves subscribers/targets through cursor
 * pagination and records every query issued so the test can assert the streaming contract (cursorAfter +
 * bounded page size, topic reads never selecting the 1M-row `targets` subquery, no runaway limits).
 */
class RecordingDatabase extends Database
{
    /**
     * @var array<array{collection: string, queries: array<Query>}>
     */
    public array $findCalls = [];

    private readonly Authorization $authorization;

    /**
     * @param array<Document> $subscribers Subscriber rows (carry $sequence + targetInternalId + providerType).
     * @param array<int|string, Document> $targets Target rows keyed by $sequence (resolved from a subscriber page).
     * @param array<Document> $userTargets Targets addressed by userId.
     * @param array<Document> $directTargets Targets addressed directly by id.
     */
    public function __construct(
        private readonly array $subscribers = [],
        private readonly array $targets = [],
        private readonly array $userTargets = [],
        private readonly array $directTargets = []
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
     * Either resolve a subscriber page's targets by $sequence, or paginate user/direct targets.
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

            if ($query->getMethod() === Query::TYPE_EQUAL && $query->getAttribute() === 'userId') {
                return $this->paginate($this->userTargets, $queries);
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
