<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Detector\Detector;
use Appwrite\Event\Message\ProjectContext;
use Appwrite\Usage\Connection;
use Swoole\Timer;
use Utopia\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Usage\Accumulator;
use Utopia\Usage\Usage;
use Utopia\UserAgent\UserAgent;

class StatsUsage extends Action
{
    /**
     * Distinct buffered rows that trigger an insert. Measured on 2.3.0: one
     * insert per job capped a process at about 1,000 jobs/s; folding into
     * batches of this size removed that ceiling.
     */
    private const int FLUSH_THRESHOLD = 500;

    /** Flush once buffered rows are this many seconds old, so a quiet queue still drains. */
    private const float FLUSH_INTERVAL = 1.0;

    /** One buffer per worker process. Jobs only read and fold; inserts happen from flushBuffer(). */
    private static ?Accumulator $accumulator = null;

    private static ?int $timerId = null;

    private static bool $timerStarted = false;

    protected const SITE_NETWORK_METRICS = [
        METRIC_SITES_INBOUND => METRIC_NETWORK_INBOUND,
        METRIC_SITES_OUTBOUND => METRIC_NETWORK_OUTBOUND,
        METRIC_SITES_REQUESTS => METRIC_NETWORK_REQUESTS,
    ];

    public static function getName(): string
    {
        return 'stats-usage';
    }

    /**
     * Write usage still held in memory. The queue server calls this from its
     * worker-stop hook, after in-flight jobs have finished collecting.
     */
    public static function flushPending(): void
    {
        self::flushBuffer(force: true);
    }

    public function __construct()
    {
        $this
            ->desc('Stats usage worker')
            ->inject('message')
            ->inject('projectContext')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(Message $message, ProjectContext $projectContext, Connection $usageConnection): void
    {
        if (!$usageConnection->isEnabled()) {
            return;
        }
        if (!$usageConnection->isReady()) {
            throw new \RuntimeException('Usage schema is not ready');
        }

        $payload = $message->getPayload();
        if ($payload === []) {
            throw new \RuntimeException('Missing payload');
        }

        $tenant = $projectContext->sequence;
        if ($tenant === '') {
            Console::warning('Skipping usage event write: project has no sequence');
            return;
        }

        try {
            $projectId = (string) ($payload['project']['$id'] ?? '');
            $timestamp = $this->timestamp($payload, $message);

            foreach ($payload['metrics'] ?? [] as $metric) {
                $key = (string) ($metric['key'] ?? '');
                $value = (int) ($metric['value'] ?? 0);
                if (
                    $key === ''
                    || $value === 0
                    || ($value < 0 && $key !== METRIC_REALTIME_CONNECTIONS)
                    || $this->shouldSkipMetric($key)
                ) {
                    continue;
                }

                $resourceType = (string) ($metric['resourceType'] ?? '');
                $resourceId = (string) ($metric['resourceId'] ?? '');
                $resourceInternalId = (string) ($metric['resourceInternalId'] ?? '');
                $storedKey = self::SITE_NETWORK_METRICS[$key] ?? $key;

                if (isset(self::SITE_NETWORK_METRICS[$key]) && ($resourceType === '' || $resourceType === 'project')) {
                    $resourceType = 'site';
                    $resourceId = '';
                    $resourceInternalId = '';
                }

                $projectScoped = $resourceType === '';
                $tags = [
                    'region' => $metric['region'] ?? '',
                    'path' => $metric['path'] ?? '',
                    'method' => $metric['method'] ?? '',
                    'status' => !empty($metric['status']) ? (string) $metric['status'] : '',
                    'service' => $metric['service'] ?? $this->inferServiceFromMetric($key),
                    'resourceType' => $resourceType === '' ? 'project' : $resourceType,
                    'resourceId' => $resourceId !== '' ? $resourceId : ($projectScoped ? $projectId : ''),
                    'resourceInternalId' => $resourceInternalId !== '' ? $resourceInternalId : ($projectScoped ? $tenant : ''),
                    'teamId' => $metric['teamId'] ?? '',
                    'teamInternalId' => $metric['teamInternalId'] ?? '',
                    'country' => $metric['country'] ?? '',
                    'hostname' => $metric['hostname'] ?? '',
                    'ip' => $metric['ip'] ?? '',
                    'sdk' => $metric['sdk'] ?? '',
                    'sdkVersion' => $metric['sdkVersion'] ?? '',
                    'protocol' => $metric['protocol'] ?? '',
                    'accept' => $metric['accept'] ?? '',
                    'acceptLanguage' => $metric['acceptLanguage'] ?? '',
                    'queryKeys' => $metric['queryKeys'] ?? '',
                ];
                $tags = array_merge($this->resolveUserAgentTags((string) ($metric['userAgent'] ?? '')), $tags);
                $tags = array_filter($tags, static fn (mixed $value): bool => $value !== '' && $value !== null);

                self::accumulator($usageConnection)->collect(
                    $tenant,
                    $storedKey,
                    $value,
                    Usage::TYPE_EVENT,
                    $tags,
                    $timestamp,
                    allowNegative: $key === METRIC_REALTIME_CONNECTIONS,
                );
            }

            self::flushBuffer();
        } catch (\Throwable $th) {
            // Usage analytics deliberately remains best-effort and inserts are
            // not retried because the adapter has no durable deduplication key.
            // Rows already folded into the process buffer stay there for the
            // next flush; this job is still acknowledged so it is not applied twice.
            Console::error('Failed to write usage events: ' . $th->getMessage());
        }
    }

    private static function accumulator(Connection $usageConnection): Accumulator
    {
        self::ensureFlushTimer();

        return self::$accumulator ??= new Accumulator($usageConnection->getUsage());
    }

    /**
     * A quiet queue has no later job to notice the interval, so the process
     * timer drains the buffer on its own. Armed on the first collect, which
     * runs in the forked worker rather than the supervisor that constructed it.
     */
    private static function ensureFlushTimer(): void
    {
        if (self::$timerStarted) {
            return;
        }

        self::$timerStarted = true;
        try {
            $timerId = Timer::tick((int) (self::FLUSH_INTERVAL * 1000), static function (): void {
                self::flushBuffer();
            });
        } catch (\Throwable) {
            self::$timerStarted = false;

            return;
        }
        if (!\is_int($timerId)) {
            self::$timerStarted = false;

            return;
        }

        self::$timerId = $timerId;
    }

    private static function flushBuffer(bool $force = false): void
    {
        if ($force && self::$timerId !== null) {
            Timer::clear(self::$timerId);
            self::$timerId = null;
        }

        $accumulator = self::$accumulator;
        if ($accumulator === null || $accumulator->count() === 0) {
            return;
        }
        if (
            !$force
            && $accumulator->count() < self::FLUSH_THRESHOLD
            && $accumulator->elapsedSeconds() < self::FLUSH_INTERVAL
        ) {
            return;
        }

        // Detach before the insert. flush() yields on HTTP, and this worker
        // runs several coroutines plus the timer. Collects that arrive during
        // the insert fold into a new buffer instead of the rows already being
        // written. The detached buffer is not put back: a failed insert is
        // dropped rather than retried, because a retry could double-count a
        // write that committed and then lost its response.
        self::$accumulator = null;

        try {
            if (!$accumulator->flush()) {
                Console::error('Usage event flush returned false');
            }
        } catch (\Throwable $th) {
            Console::error('Failed to write usage events: ' . $th->getMessage());
        }
    }

    protected function shouldSkipMetric(string $metric): bool
    {
        return in_array($metric, [
            METRIC_DATABASES,
            METRIC_BUCKETS,
            METRIC_USERS,
            METRIC_FUNCTIONS,
            METRIC_TEAMS,
            METRIC_MESSAGES,
            METRIC_MAU,
            METRIC_DAU,
            METRIC_WAU,
            METRIC_WEBHOOKS,
            METRIC_PLATFORMS,
            METRIC_PROVIDERS,
            METRIC_TOPICS,
            METRIC_KEYS,
            METRIC_DOMAINS,
            METRIC_SITES,
            METRIC_TARGETS,
            METRIC_FILES,
            METRIC_FILES_STORAGE,
            METRIC_DEPLOYMENTS_STORAGE,
            METRIC_BUILDS_STORAGE,
            METRIC_DEPLOYMENTS,
            METRIC_BUILDS,
            METRIC_COLLECTIONS,
            METRIC_DOCUMENTS,
            METRIC_DATABASES_STORAGE,
        ], true);
    }

    /** @return array<string, string> */
    protected function resolveUserAgentTags(string $userAgent): array
    {
        if ($userAgent === '') {
            return [];
        }

        try {
            if (UserAgent::parse($userAgent)->isBot()) {
                return [];
            }

            $detector = new Detector($userAgent);
            return array_filter(
                array_merge($detector->getOS(), $detector->getClient(), $detector->getDevice()),
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );
        } catch (\Throwable) {
            return [];
        }
    }

    protected function inferServiceFromMetric(string $metric): string
    {
        return match (explode('.', $metric)[0]) {
            'files', 'buckets' => 'storage',
            'databases', 'collections', 'documents', 'documentsdb', 'vectorsdb' => 'databases',
            'functions', 'deployments', 'builds', 'executions' => 'functions',
            'sites' => 'sites',
            'users', 'sessions', 'auth', 'mau', 'dau', 'wau', 'teams' => 'users',
            'messages', 'targets', 'topics', 'providers' => 'messaging',
            'webhooks' => 'webhooks',
            'network' => 'network',
            'domains', 'platforms', 'keys' => 'project',
            default => '',
        };
    }

    /** @param array<string, mixed> $payload */
    private function timestamp(array $payload, Message $message): \DateTime
    {
        try {
            if (!empty($payload['timestamp']) && is_scalar($payload['timestamp'])) {
                return new \DateTime((string) $payload['timestamp']);
            }
        } catch (\Throwable) {
        }

        return new \DateTime('@' . $message->getTimestamp());
    }
}
