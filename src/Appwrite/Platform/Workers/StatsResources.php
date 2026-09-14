<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Platform\Action;
use Appwrite\Usage\Connection;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
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
            ->inject('getDatabasesDB')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        callable $getDatabasesDB,
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
            $gauges = $this->count($project, $dbForProject, $dbForPlatform, $getDatabasesDB);
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

    /** @return array<int, array<string, mixed>> */
    protected function count(Document $project, Database $dbForProject, Database $dbForPlatform, callable $getDatabasesDB): array
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

        array_push($gauges, ...$this->bucketGauges($project, $dbForProject));
        array_push($gauges, ...$this->databaseGauges($project, $dbForProject, $getDatabasesDB));
        array_push($gauges, ...$this->deploymentGauges($project, $dbForProject));

        return $gauges;
    }

    /**
     * Per-bucket file counts and sizes, plus project totals. Each bucket also
     * feeds the unified `storage` gauge the console reads per resource.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bucketGauges(Document $project, Database $dbForProject): array
    {
        $gauges = [];
        $totalFiles = 0;
        $totalStorage = 0;
        $complete = true;

        $this->foreachDocument($dbForProject, 'buckets', [], function (Document $bucket) use ($dbForProject, &$gauges, &$totalFiles, &$totalStorage, &$complete): void {
            $files = 'bucket_' . $bucket->getSequence();

            try {
                $count = $dbForProject->count($files);
                $storage = (int) $dbForProject->sum($files, 'sizeActual');
            } catch (\Throwable $th) {
                $complete = false;
                Console::warning("Failed to measure bucket {$bucket->getId()}: " . $th->getMessage());
                return;
            }

            $gauges[] = ['metric' => METRIC_FILES, 'value' => $count, 'service' => 'storage', 'resourceType' => 'bucket', 'resourceId' => $bucket->getId()];
            $gauges[] = ['metric' => METRIC_STORAGE, 'value' => $storage, 'service' => 'storage', 'resourceType' => 'bucket', 'resourceId' => $bucket->getId()];
            $totalFiles += $count;
            $totalStorage += $storage;
        });

        // A partial sweep would understate the totals; per-bucket rows for the
        // buckets that did measure are still worth keeping.
        if ($complete) {
            $gauges[] = ['metric' => METRIC_FILES, 'value' => $totalFiles, 'service' => 'storage', 'resourceType' => 'project', 'resourceId' => $project->getId()];
            $gauges[] = ['metric' => METRIC_FILES_STORAGE, 'value' => $totalStorage, 'service' => 'storage', 'resourceType' => 'project', 'resourceId' => $project->getId()];
        }

        return $gauges;
    }

    /**
     * Per-database collection/document counts and data sizes, plus project
     * totals per database type. Sizes come from each database's own adapter
     * (legacy, documentsdb, vectorsdb) via the injected resolver.
     *
     * @return array<int, array<string, mixed>>
     */
    private function databaseGauges(Document $project, Database $dbForProject, callable $getDatabasesDB): array
    {
        $gauges = [];
        $totals = [
            METRIC_COLLECTIONS => 0,
            METRIC_DOCUMENTS => 0,
            METRIC_DATABASES_STORAGE => 0,
            METRIC_COLLECTIONS_DOCUMENTSDB => 0,
            METRIC_DOCUMENTS_DOCUMENTSDB => 0,
            METRIC_DATABASES_STORAGE_DOCUMENTSDB => 0,
            METRIC_COLLECTIONS_VECTORSDB => 0,
            METRIC_DOCUMENTS_VECTORSDB => 0,
            METRIC_DATABASES_STORAGE_VECTORSDB => 0,
        ];
        $complete = true;

        $this->foreachDocument($dbForProject, 'databases', [], function (Document $database) use ($dbForProject, $getDatabasesDB, &$gauges, &$totals, &$complete): void {
            $databaseSequence = $database->getSequence();
            $type = (string) $database->getAttribute('type', '');
            $prefix = ($type !== '' && $type !== DATABASE_TYPE_LEGACY && $type !== DATABASE_TYPE_TABLESDB) ? $type . '.' : '';

            try {
                $dbForDatabases = $getDatabasesDB($database);
                $collections = $dbForProject->count('database_' . $databaseSequence);

                $documents = 0;
                $storage = 0;
                $this->foreachDocument($dbForProject, 'database_' . $databaseSequence, [], function (Document $collection) use ($dbForDatabases, $databaseSequence, &$documents, &$storage): void {
                    $data = 'database_' . $databaseSequence . '_collection_' . $collection->getSequence();
                    $documents += $dbForDatabases->count($data);
                    $storage += $dbForDatabases->getSizeOfCollection($data);
                });
            } catch (\Throwable $th) {
                $complete = false;
                Console::warning("Failed to measure database {$database->getId()}: " . $th->getMessage());
                return;
            }

            $gauges[] = ['metric' => $prefix . METRIC_COLLECTIONS, 'value' => $collections, 'service' => 'databases', 'resourceType' => 'database', 'resourceId' => $database->getId()];
            $gauges[] = ['metric' => $prefix . METRIC_DOCUMENTS, 'value' => $documents, 'service' => 'databases', 'resourceType' => 'database', 'resourceId' => $database->getId()];
            $gauges[] = ['metric' => $prefix . METRIC_DATABASES_STORAGE, 'value' => $storage, 'service' => 'databases', 'resourceType' => 'database', 'resourceId' => $database->getId()];
            $gauges[] = ['metric' => METRIC_STORAGE, 'value' => $storage, 'service' => 'databases', 'resourceType' => 'database', 'resourceId' => $database->getId()];

            $totals[$prefix . METRIC_COLLECTIONS] = ($totals[$prefix . METRIC_COLLECTIONS] ?? 0) + $collections;
            $totals[$prefix . METRIC_DOCUMENTS] = ($totals[$prefix . METRIC_DOCUMENTS] ?? 0) + $documents;
            $totals[$prefix . METRIC_DATABASES_STORAGE] = ($totals[$prefix . METRIC_DATABASES_STORAGE] ?? 0) + $storage;
        });

        if (!$complete) {
            Console::warning('Skipping project database totals for ' . $project->getId() . '; at least one database failed to measure');
            return $gauges;
        }

        foreach ($totals as $metric => $value) {
            $gauges[] = ['metric' => $metric, 'value' => $value, 'service' => 'databases', 'resourceType' => 'project', 'resourceId' => $project->getId()];
        }

        return $gauges;
    }

    /**
     * Deployment and build counts and sizes: project totals, per-resource-type
     * totals, and per-function / per-site rows that also feed the unified
     * `storage` gauge.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deploymentGauges(Document $project, Database $dbForProject): array
    {
        try {
            $gauges = [
                ['metric' => METRIC_DEPLOYMENTS_STORAGE, 'value' => (int) $dbForProject->sum('deployments', 'sourceSize'), 'service' => '', 'resourceType' => 'deployment', 'resourceId' => $project->getId()],
                ['metric' => METRIC_BUILDS_STORAGE, 'value' => (int) $dbForProject->sum('deployments', 'buildSize'), 'service' => '', 'resourceType' => 'build', 'resourceId' => $project->getId()],
                ['metric' => METRIC_DEPLOYMENTS, 'value' => $dbForProject->count('deployments'), 'service' => '', 'resourceType' => 'deployment', 'resourceId' => $project->getId()],
                ['metric' => METRIC_BUILDS, 'value' => $dbForProject->count('deployments'), 'service' => '', 'resourceType' => 'build', 'resourceId' => $project->getId()],
            ];
        } catch (\Throwable $th) {
            Console::warning("Failed to measure deployments for {$project->getId()}: " . $th->getMessage());
            return [];
        }

        array_push($gauges, ...$this->computeGauges($project, $dbForProject, RESOURCE_TYPE_FUNCTIONS, 'functions', 'function', 'functions'));
        array_push($gauges, ...$this->computeGauges($project, $dbForProject, RESOURCE_TYPE_SITES, 'sites', 'site', 'sites'));

        return $gauges;
    }

    /**
     * Deployment gauges for one compute kind (functions or sites): the
     * per-resource-type project totals, then one row set per function or site.
     *
     * @return array<int, array<string, mixed>>
     */
    private function computeGauges(Document $project, Database $dbForProject, string $resourceType, string $service, string $resource, string $collection): array
    {
        $byResourceType = [Query::equal('resourceType', [$resourceType])];

        try {
            $gauges = [
                ['metric' => str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), 'value' => (int) $dbForProject->sum('deployments', 'sourceSize', $byResourceType), 'service' => $service, 'resourceType' => 'deployment', 'resourceId' => $project->getId()],
                ['metric' => str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_BUILDS_STORAGE), 'value' => (int) $dbForProject->sum('deployments', 'buildSize', $byResourceType), 'service' => $service, 'resourceType' => 'build', 'resourceId' => $project->getId()],
                ['metric' => str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_DEPLOYMENTS), 'value' => $dbForProject->count('deployments', $byResourceType), 'service' => $service, 'resourceType' => 'deployment', 'resourceId' => $project->getId()],
                ['metric' => str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_BUILDS), 'value' => $dbForProject->count('deployments', $byResourceType), 'service' => $service, 'resourceType' => 'build', 'resourceId' => $project->getId()],
            ];
        } catch (\Throwable $th) {
            Console::warning("Failed to measure {$resourceType} deployments for {$project->getId()}: " . $th->getMessage());
            return [];
        }

        $this->foreachDocument($dbForProject, $collection, [], function (Document $document) use ($dbForProject, $resourceType, $service, $resource, &$gauges): void {
            $byResource = [
                Query::equal('resourceInternalId', [$document->getSequence()]),
                Query::equal('resourceType', [$resourceType]),
            ];

            try {
                $deploymentsStorage = (int) $dbForProject->sum('deployments', 'sourceSize', $byResource);
                $buildsStorage = (int) $dbForProject->sum('deployments', 'buildSize', $byResource);
                $deployments = $dbForProject->count('deployments', $byResource);
            } catch (\Throwable $th) {
                Console::warning("Failed to measure {$resource} {$document->getId()}: " . $th->getMessage());
                return;
            }

            $gauges[] = ['metric' => METRIC_DEPLOYMENTS_STORAGE, 'value' => $deploymentsStorage, 'service' => $service, 'resourceType' => $resource, 'resourceId' => $document->getId()];
            $gauges[] = ['metric' => METRIC_BUILDS_STORAGE, 'value' => $buildsStorage, 'service' => $service, 'resourceType' => $resource, 'resourceId' => $document->getId()];
            $gauges[] = ['metric' => METRIC_DEPLOYMENTS, 'value' => $deployments, 'service' => $service, 'resourceType' => $resource, 'resourceId' => $document->getId()];
            // Deployments and builds are 1-1, so the counts match.
            $gauges[] = ['metric' => METRIC_BUILDS, 'value' => $deployments, 'service' => $service, 'resourceType' => $resource, 'resourceId' => $document->getId()];
            $gauges[] = ['metric' => METRIC_STORAGE, 'value' => $deploymentsStorage + $buildsStorage, 'service' => $service, 'resourceType' => $resource, 'resourceId' => $document->getId()];
        });

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
