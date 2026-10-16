<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Detector\Detector;
use Appwrite\Event\Message\ProjectContext;
use Appwrite\Usage\Connection;
use Swoole\Timer;
use Utopia\Console\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Usage\Accumulator;
use Utopia\Usage\Usage;
use Utopia\UserAgent\UserAgent;

class StatsUsage extends Action
{
    protected const SITE_NETWORK_METRICS = [
        METRIC_SITES_INBOUND => METRIC_NETWORK_INBOUND,
        METRIC_SITES_OUTBOUND => METRIC_NETWORK_OUTBOUND,
        METRIC_SITES_REQUESTS => METRIC_NETWORK_REQUESTS,
    ];

    /**
     * Messages fold into one batch, written this long after the batch opens.
     * Each ClickHouse insert is a new part sorted into every projection and
     * merged again later, so an insert per message kept small servers busy.
     * A stopping worker waits for the batch, so this stays under Docker's
     * 10 second stop timeout.
     */
    private const int FLUSH_DELAY_MS = 5_000;

    /**
     * The open batch, one accumulator per minute of event time. A folded event
     * keeps the earliest time it absorbed, so events from different minutes
     * must not share one, or usage slides into an earlier minute, hour or day.
     *
     * @var array<int, Accumulator>
     */
    private array $accumulators = [];

    public static function getName(): string
    {
        return 'stats-usage';
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
            if ($this->accumulators === []) {
                // One-shot rather than a tick: a pending timer keeps the worker's
                // event loop alive only until it fires, so shutdown still writes it.
                Timer::after(self::FLUSH_DELAY_MS, $this->flush(...));
            }

            $projectId = (string) ($payload['project']['$id'] ?? '');
            $timestamp = $this->timestamp($payload, $message);
            $accumulator = $this->accumulators[\intdiv($timestamp->getTimestamp(), 60)] ??= new Accumulator($usageConnection->getUsage());

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

                $accumulator->collect(
                    $tenant,
                    $storedKey,
                    $value,
                    Usage::TYPE_EVENT,
                    $tags,
                    $timestamp,
                    allowNegative: $key === METRIC_REALTIME_CONNECTIONS,
                );
            }
        } catch (\Throwable $th) {
            Console::error('Failed to collect usage events: ' . $th->getMessage());
        }
    }

    private function flush(): void
    {
        // Detach first, so messages handled while the insert is in flight open the next batch.
        $accumulators = $this->accumulators;
        $this->accumulators = [];

        foreach ($accumulators as $accumulator) {
            if ($accumulator->count() === 0) {
                continue;
            }

            try {
                if (!$accumulator->flush()) {
                    Console::error('Usage event flush returned false');
                }
            } catch (\Throwable $th) {
                // Usage analytics deliberately remains best-effort and inserts are
                // not retried because the adapter has no durable deduplication key.
                Console::error('Failed to write usage events: ' . $th->getMessage());
            }
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
