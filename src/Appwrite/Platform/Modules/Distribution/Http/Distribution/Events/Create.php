<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Events;

use Appwrite\Abuse\Limit;
use Appwrite\Auth\JobsSecret;
use Appwrite\Distribution\Builds;
use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Callback\CloudEvent;
use OpenRuntimes\Orchestrator\Exception\ClientException;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * A verified event that cannot be applied is a 204, not a 4xx: the orchestrator
 * never retries a 4xx and opens its per-host circuit breaker after five in a row.
 */
class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/events')
            ->desc('Create event')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('publisherForDistributionBuilds')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Document $project,
        DistributionBuildPublisher $publisherForDistributionBuilds,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        $body = $request->getRawPayload();
        $signature = $request->getHeaderLine('x-signature-256');
        $build = JobsSecret::verify($body, $signature, Builds::secret(Builds::STAGE_BUILD));
        $signing = JobsSecret::verify($body, $signature, Builds::secret(Builds::STAGE_SIGN));

        if (!$build && !$signing) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid distribution event signature.');
        }

        $event = $this->decode($body);
        $meta = $event === null || !\is_array($event->data['meta'] ?? null) ? [] : $event->data['meta'];

        // The untrusted build plane holds only the build key, so a build-signed inspect or sign event is a forgery.
        $verified = match ($meta['stage'] ?? '') {
            Builds::STAGE_BUILD => $build,
            Builds::STAGE_INSPECT, Builds::STAGE_SIGN => $signing,
            default => null,
        };

        if ($verified === false) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid distribution event signature.');
        }

        if (
            $event === null
            || $verified === null
            || Builds::stale($event->time->getTimestamp(), \time())
            || ($meta['projectId'] ?? '') !== $project->getId()
        ) {
            $response->noContent();
            return;
        }

        $publisherForDistributionBuilds->enqueue(new DistributionBuildMessage(
            project: $project,
            action: DistributionBuildAction::Event,
            id: $event->id,
            event: $event->type,
            data: $event->data,
        ));

        $response->noContent();
    }

    /**
     * @return CloudEvent<array<string, mixed>>|null
     */
    private function decode(string $body): ?CloudEvent
    {
        $payload = \json_decode($body, true);
        if (!\is_array($payload)) {
            return null;
        }

        try {
            return CloudEvent::fromArray($payload);
        } catch (ClientException) {
            return null;
        }
    }
}
