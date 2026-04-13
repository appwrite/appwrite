<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Bus\Events\RequestCompleted;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as Publisher;
use Appwrite\Usage\Context;
use Utopia\Bus\Event;
use Utopia\Bus\Listener;
use Utopia\Database\Document;

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
        ];
    }

    public function __construct()
    {
        $this
            ->desc('Records usage metrics')
            ->inject('publisherForUsage')
            ->inject('usage')
            ->callback($this->handle(...));
    }

    public function handle(Event $event, Publisher $publisherForUsage, Context $usage): void
    {
        match (true) {
            $event instanceof ExecutionCompleted => $this->handleExecutionCompleted($event, $publisherForUsage),
            $event instanceof RequestCompleted => $this->handleRequestCompleted($event, $usage),
            default => null,
        };
    }

    private function handleExecutionCompleted(ExecutionCompleted $event, Publisher $publisherForUsage): void
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

        $context = (new Context())
            ->addMetric(METRIC_EXECUTIONS, 1)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_EXECUTIONS), 1)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$resourceType, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_EXECUTIONS), 1)
            ->addMetric(METRIC_EXECUTIONS_COMPUTE, $compute)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE), $compute)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$resourceType, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE), $compute)
            ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, $mbSeconds)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS), $mbSeconds)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$resourceType, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS), $mbSeconds);

        $message = new UsageMessage(
            project: $project,
            metrics: $context->getMetrics(),
            reduce: $context->getReduce()
        );

        $publisherForUsage->enqueue($message);
    }

    private function handleRequestCompleted(RequestCompleted $event, Context $usage): void
    {
        $fileSize = 0;
        $file = $event->request->getFiles('file');
        if (!empty($file)) {
            $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
        }

        $deployment = new Document($event->deployment);

        $inbound = $event->request->getSize() + $fileSize;
        $outbound = $event->response->getSize();

        if ($deployment->getAttribute('resourceType') === 'sites') {
            $siteInternalId = $deployment->getAttribute('resourceInternalId', '');
            $usage
                ->addMetric(METRIC_SITES_REQUESTS, 1)
                ->addMetric(METRIC_SITES_INBOUND, $inbound)
                ->addMetric(METRIC_SITES_OUTBOUND, $outbound)
                ->addMetric(str_replace('{siteInternalId}', $siteInternalId, METRIC_SITES_ID_REQUESTS), 1)
                ->addMetric(str_replace('{siteInternalId}', $siteInternalId, METRIC_SITES_ID_INBOUND), $inbound)
                ->addMetric(str_replace('{siteInternalId}', $siteInternalId, METRIC_SITES_ID_OUTBOUND), $outbound);
        } else {
            $usage
                ->addMetric(METRIC_NETWORK_REQUESTS, 1)
                ->addMetric(METRIC_NETWORK_INBOUND, $inbound)
                ->addMetric(METRIC_NETWORK_OUTBOUND, $outbound);
        }
    }
}
