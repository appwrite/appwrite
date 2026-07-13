<?php

namespace Appwrite\Platform\Modules\Functions\Http\Jobs\Event;

use Appwrite\Event\Message\Jobs as JobsMessage;
use Appwrite\Event\Publisher\Jobs as JobsPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Callback\Signature;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * Ingests an open-runtimes jobs-service CloudEvents callback. Verifies the
 * HMAC signature, then hands the event off to the jobs worker which applies
 * it to the deployment. Kept thin on purpose — the request returns
 * immediately so the jobs-service delivery loop isn't blocked.
 */
class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createJobEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/jobs/event')
            ->groups(['api'])
            ->desc('Create job event')
            ->label('scope', 'public')
            ->inject('request')
            ->inject('response')
            ->inject('publisherForJobs')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        JobsPublisher $publisherForJobs,
    ): void {
        $body = $request->getRawPayload();
        $secret = System::getEnv('_APP_JOBS_SECRET', '');
        $signature = $request->getHeaderLine('x-signature-256');

        if ($secret === '' || ! Signature::verify($body, $signature, $secret)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid jobs signature.');
        }

        $event = \json_decode($body, true) ?? [];

        // The project isn't a request-scoped resource here (the jobs-service is
        // the caller), so resolve it from the job meta Appwrite authored. The
        // worker reloads the full project document from this id.
        $projectId = $event['data']['meta']['projectId'] ?? '';

        $publisherForJobs->enqueue(new JobsMessage(
            project: new Document(['$id' => $projectId]),
            id: $event['id'] ?? '',
            event: $event['type'] ?? '',
            data: $event['data'] ?? [],
        ));

        $response->noContent();
    }
}
