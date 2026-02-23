<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\ExecutionCompleted;
use Appwrite\Bus\RequestCompleted;
use Appwrite\Bus\SiteRequestCompleted;
use Appwrite\Event\StatsUsage;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Bus\Event;
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
        return [
            ExecutionCompleted::class,
            RequestCompleted::class,
            SiteRequestCompleted::class,
        ];
    }

    public function __construct()
    {
        $this
            ->desc('Records usage metrics')
            ->inject('publisher')
            ->callback($this->handle(...));
    }

    public function handle(Event $event, Publisher $publisher): void
    {
        match (true) {
            $event instanceof ExecutionCompleted => $this->handleExecutionCompleted($event, $publisher),
            $event instanceof SiteRequestCompleted => $this->handleSiteRequestCompleted($event, $publisher),
            $event instanceof RequestCompleted => $this->handleRequestCompleted($event, $publisher),
            default => null,
        };
    }

    private function handleExecutionCompleted(ExecutionCompleted $event, Publisher $publisher): void
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

    private function handleRequestCompleted(RequestCompleted $event, Publisher $publisher): void
    {
        $fileSize = 0;
        $file = $event->request->getFiles('file');
        if (!empty($file)) {
            $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
        }

        $queueForStatsUsage = new StatsUsage($publisher);
        $queueForStatsUsage
            ->setProject(new Document($event->project))
            ->addMetric(METRIC_NETWORK_REQUESTS, 1)
            ->addMetric(METRIC_NETWORK_INBOUND, $event->request->getSize() + $fileSize)
            ->addMetric(METRIC_NETWORK_OUTBOUND, $event->response->getSize())
            ->trigger();
    }

    private function handleSiteRequestCompleted(SiteRequestCompleted $event, Publisher $publisher): void
    {
        $fileSize = 0;
        $file = $event->request->getFiles('file');
        if (!empty($file)) {
            $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
        }

        $queueForStatsUsage = new StatsUsage($publisher);
        $queueForStatsUsage
            ->setProject(new Document($event->project))
            ->addMetric(METRIC_SITES_REQUESTS, 1)
            ->addMetric(METRIC_SITES_INBOUND, $event->request->getSize() + $fileSize)
            ->addMetric(METRIC_SITES_OUTBOUND, $event->response->getSize())
            ->addMetric(str_replace('{siteInternalId}', $event->siteInternalId, METRIC_SITES_ID_REQUESTS), 1)
            ->addMetric(str_replace('{siteInternalId}', $event->siteInternalId, METRIC_SITES_ID_INBOUND), $event->request->getSize() + $fileSize)
            ->addMetric(str_replace('{siteInternalId}', $event->siteInternalId, METRIC_SITES_ID_OUTBOUND), $event->response->getSize())
            ->trigger();
    }
}
