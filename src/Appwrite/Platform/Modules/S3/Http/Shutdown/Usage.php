<?php

namespace Appwrite\Platform\Modules\S3\Http\Shutdown;

use Appwrite\Bus\Events\RequestCompleted;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;

/**
 * Meters S3 gateway traffic. Mirrors the `api` group's usage shutdown hook in
 * app/controllers/shared/api.php, which does not run for the `s3`
 * group.
 */
class Usage extends Action
{
    public static function getName(): string
    {
        return 'shutdownUsage';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_SHUTDOWN)
            ->groups(['s3'])
            ->desc('S3 gateway usage metering')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('user')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('authorization')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, Document $project, User $user, UsageContext $usage, UsagePublisher $publisherForUsage, Authorization $authorization, Bus $bus): void
    {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return;
        }

        $usage->setStatus($response->getStatusCode());
        if ($usage->getResourcePath() === '') {
            $usage->fillMissingResource('project', $project->getId(), (string) $project->getSequence());
        }

        // The usage bus listener adds METRIC_NETWORK_REQUESTS/INBOUND/OUTBOUND,
        // exactly as on the `api` group. The SigV4 key never becomes the
        // `apiKey` resource, so per-key disabled-metrics filtering cannot
        // apply here.
        if (!$user->isPrivileged($authorization->getRoles())) {
            $bus->dispatch(new RequestCompleted(
                project: $project->getArrayCopy(),
                request: $request,
                response: $response,
            ));
        }

        if (!$usage->isEmpty()) {
            $publisherForUsage->enqueue(new UsageMessage(
                project: $project,
                metrics: $usage->getMetrics(),
                reduce: $usage->getReduce(),
            ));
        }
    }
}
