<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Usage\Connection;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Usage\Accumulator;
use Utopia\Usage\Usage;

class StatsResources extends Action
{
    public static function getName(): string
    {
        return 'stats-resources';
    }

    public function __construct()
    {
        $this
            ->desc('Stats resources worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Connection $usageConnection,
    ): void {
        if (!$usageConnection->isEnabled()) {
            return;
        }
        if (!$usageConnection->isReady()) {
            throw new \RuntimeException('Usage schema is not ready');
        }

        $statsResources = StatsResourcesMessage::fromArray($message->getPayload());
        if ($statsResources->project->isEmpty()) {
            throw new \RuntimeException('Missing payload');
        }

        if ($statsResources->project->getId() !== $project->getId()) {
            throw new \RuntimeException('Stats resources payload project does not match resolved project');
        }

        $tenant = (string) $project->getSequence();
        if ($tenant === '' || $project->getAttribute('database', '') === '') {
            return;
        }

        $gauges = $statsResources->gauges;
        if ($gauges === []) {
            $gauges = $this->count($project, $dbForProject, $dbForPlatform);
        }

        try {
            $accumulator = new Accumulator($usageConnection->getUsage());
            foreach ($gauges as $gauge) {
                $metric = $gauge['metric'];
                if ($metric === '') {
                    continue;
                }

                $tags = array_filter([
                    'service' => $gauge['service'] ?? $this->serviceForMetric($metric),
                    'resourceType' => $gauge['resourceType'] ?? '',
                    'resourceId' => $gauge['resourceId'] ?? '',
                    'resourceInternalId' => $gauge['resourceInternalId'] ?? '',
                    'ordinal' => isset($gauge['ordinal']) ? (string) $gauge['ordinal'] : '',
                ], static fn (string $value): bool => $value !== '');

                $accumulator->collect(
                    $tenant,
                    $metric,
                    $gauge['value'],
                    Usage::TYPE_GAUGE,
                    $tags,
                );
            }

            if ($accumulator->count() > 0 && !$accumulator->flush()) {
                Console::error('Usage gauge flush returned false for project: ' . $project->getId());
            }
        } catch (\Throwable $th) {
            Console::error('Failed to write usage gauges: ' . $th->getMessage());
        }
    }

    /** @return array<int, array{metric: string, value: int}> */
    protected function count(Document $project, Database $dbForProject, Database $dbForPlatform): array
    {
        $projectFilter = [Query::equal('projectInternalId', [$project->getSequence()])];
        $last30Days = (new \DateTime())->sub(new \DateInterval('P30D'))->format('Y-m-d 00:00:00');
        $last7Days = (new \DateTime())->sub(new \DateInterval('P7D'))->format('Y-m-d 00:00:00');
        $lastDay = (new \DateTime())->sub(new \DateInterval('P1D'))->format('Y-m-d 00:00:00');

        $metrics = [
            METRIC_DATABASES => $this->safeCount($dbForProject, 'databases'),
            METRIC_BUCKETS => $this->safeCount($dbForProject, 'buckets'),
            METRIC_USERS => $this->safeCount($dbForProject, 'users'),
            METRIC_FUNCTIONS => $this->safeCount($dbForProject, 'functions'),
            METRIC_SITES => $this->safeCount($dbForProject, 'sites'),
            METRIC_TEAMS => $this->safeCount($dbForProject, 'teams'),
            METRIC_MESSAGES => $this->safeCount($dbForProject, 'messages'),
            METRIC_PROVIDERS => $this->safeCount($dbForProject, 'providers'),
            METRIC_TOPICS => $this->safeCount($dbForProject, 'topics'),
            METRIC_TARGETS => $this->safeCount($dbForProject, 'targets'),
            METRIC_MAU => $this->safeCount($dbForProject, 'users', [Query::greaterThanEqual('accessedAt', $last30Days)]),
            METRIC_WAU => $this->safeCount($dbForProject, 'users', [Query::greaterThanEqual('accessedAt', $last7Days)]),
            METRIC_DAU => $this->safeCount($dbForProject, 'users', [Query::greaterThanEqual('accessedAt', $lastDay)]),
            METRIC_PLATFORMS => $this->safeCount($dbForPlatform, 'platforms', $projectFilter),
            METRIC_WEBHOOKS => $this->safeCount($dbForPlatform, 'webhooks', $projectFilter),
        ];

        $gauges = [];
        foreach ($metrics as $metric => $value) {
            if ($value !== null) {
                $gauges[] = ['metric' => $metric, 'value' => $value];
            }
        }

        return $gauges;
    }

    /** @param array<int, Query> $queries */
    private function safeCount(Database $database, string $collection, array $queries = []): ?int
    {
        try {
            return $database->count($collection, $queries);
        } catch (\Throwable $th) {
            Console::warning("Failed to count {$collection}: " . $th->getMessage());
            return null;
        }
    }

    private function serviceForMetric(string $metric): string
    {
        return match (explode('.', $metric)[0]) {
            'files', 'buckets' => 'storage',
            'databases', 'collections', 'documents', 'documentsdb', 'vectorsdb' => 'databases',
            'functions', 'deployments', 'builds' => 'functions',
            'sites' => 'sites',
            'messages', 'targets', 'topics', 'providers' => 'messaging',
            'webhooks' => 'webhooks',
            default => 'project',
        };
    }
}
