<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\ExecutionCompleted;
use Appwrite\Event\StatsUsage;
use Utopia\Bus\Listener;
use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Usage extends Listener
{
    public static function getName(): string
    {
        return 'usage';
    }

    public static function getEvents(): array
    {
        return [ExecutionCompleted::class];
    }

    public function __construct()
    {
        $this
            ->desc('Records execution usage metrics')
            ->inject('publisher')
            ->callback($this->handle(...));
    }

    public function handle(ExecutionCompleted $event, Publisher $publisher): void
    {
        $execution = new Document($event->execution);
        $resource = new Document($event->resource);

        // Non-SSR sites don't record execution metrics
        if ($execution->getAttribute('resourceType') === 'sites' && $resource->getAttribute('adapter') !== 'ssr') {
            return;
        }
        $project = new Document($event->project);
        $spec = $event->spec;

        $resourceType = $execution->getAttribute('resourceType', '');
        $resourceInternalId = $execution->getAttribute('resourceInternalId', '');
        $duration = $execution->getAttribute('duration', 0);

        $compute = (int)($duration * 1000);
        $mbSeconds = (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $duration * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT));

        $queueForStatsUsage = new StatsUsage($publisher);
        $queueForStatsUsage
            ->setProject($project)
            ->addMetric(METRIC_EXECUTIONS, 1)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_EXECUTIONS), 1)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$resourceType, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_EXECUTIONS), 1)
            ->addMetric(METRIC_EXECUTIONS_COMPUTE, $compute)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE), $compute)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$resourceType, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE), $compute)
            ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, $mbSeconds)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS), $mbSeconds)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$resourceType, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS), $mbSeconds)
            ->trigger();
    }
}
