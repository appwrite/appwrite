<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Events;

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
        return 'createSiteDeploymentEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/events')
            ->groups(['api', 'sites'])
            ->desc('Create site deployment event')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('publisherForBuilds')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Request $request,
        Database $dbForProject,
        Document $project,
        BuildPublisher $publisherForBuilds
    ) {
        $body = $request->getRawPayload();
        $secret = System::getEnv('_APP_ORCHESTRATOR_CALLBACK_SECRET', System::getEnv('_APP_OPENSSL_KEY_V1', ''));
        if (! Signature::verify($body, $request->getHeader('x-signature-256', ''), $secret)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid orchestrator event signature.');
        }

        $event = \json_decode($body, true);
        if (!\is_array($event)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid orchestrator event payload.');
        }

        $site = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $siteId));
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $publisherForBuilds->enqueue(new BuildMessage(
            project: $project,
            resource: $site,
            deployment: $deployment,
            type: BUILD_TYPE_ORCHESTRATOR_EVENT,
            event: $event,
        ));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->json(['queued' => true]);
    }
}
