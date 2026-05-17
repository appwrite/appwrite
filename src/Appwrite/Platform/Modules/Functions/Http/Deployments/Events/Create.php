<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Events;

use Appwrite\Builds\OrchestratorToken;
use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
            ->setHttpPath('/v1/functions/:functionId/deployments/:deploymentId/events')
            ->groups(['api', 'functions'])
            ->desc('Create deployment event')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('publisherForBuilds')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        Response $response,
        Request $request,
        Database $dbForProject,
        Document $project,
        BuildPublisher $publisherForBuilds
    ) {
        $body = $request->getRawPayload();
        OrchestratorToken::verifySignature($body, $request->getHeader('x-signature-256', ''));

        $event = \json_decode($body, true);
        if (!\is_array($event)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid orchestrator event payload.');
        }

        $function = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('functions', $functionId));
        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $publisherForBuilds->enqueue(new BuildMessage(
            project: $project,
            resource: $function,
            deployment: $deployment,
            type: BUILD_TYPE_ORCHESTRATOR_EVENT,
            event: $event,
        ));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->json(['queued' => true]);
    }
}
