<?php

namespace Appwrite\Platform\Modules\Deployments\Http\Deployments\Events;

use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Callback\Signature;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDeploymentEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/deployments/:deploymentId/events')
            ->groups(['api', 'deployments'])
            ->desc('Create deployment event')
            ->label('scope', 'public')
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('publisherForBuilds')
            ->callback($this->action(...));
    }

    public function action(
        string $deploymentId,
        Response $response,
        Request $request,
        Database $dbForProject,
        Document $project,
        BuildPublisher $publisherForBuilds
    ) {
        $body = $request->getRawPayload();
        $secret = System::getEnv('_APP_ORCHESTRATOR_CALLBACK_SECRET', '');
        if (empty($secret)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, '_APP_ORCHESTRATOR_CALLBACK_SECRET environment variable is required.');
        }
        if (! Signature::verify($body, $request->getHeader('x-signature-256', ''), $secret)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid orchestrator event signature.');
        }

        $event = \json_decode($body, true);
        if (!\is_array($event)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid orchestrator event payload.');
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $resourceType = $deployment->getAttribute('resourceType');
        $resourceId = $deployment->getAttribute('resourceId');

        if ($resourceType === RESOURCE_TYPE_FUNCTIONS) {
            $resource = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('functions', $resourceId));
            $notFound = Exception::FUNCTION_NOT_FOUND;
        } elseif ($resourceType === RESOURCE_TYPE_SITES) {
            $resource = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $resourceId));
            $notFound = Exception::SITE_NOT_FOUND;
        } else {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($resource->isEmpty()) {
            throw new Exception($notFound);
        }

        if ($deployment->getAttribute('resourceId') !== $resource->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $publisherForBuilds->enqueue(new BuildMessage(
            project: $project,
            resource: $resource,
            deployment: $deployment,
            type: BUILD_TYPE_ORCHESTRATOR_EVENT,
            event: $event,
        ));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->json(['queued' => true]);
    }
}
