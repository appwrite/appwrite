<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class UpdateDeployment extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId')
            ->desc('Update deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('event', 'sites.[siteId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'updateDeployment')
            ->label('sdk.description', '/docs/references/sites/update-site-deployment.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_SITE)
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('dbForConsole')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Response $response, Database $dbForProject, Event $queueForEvents, Database $dbForConsole)
    {
        $site = $dbForProject->getDocument('sites', $siteId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        $site = $dbForProject->updateDocument('sites', $site->getId(), new Document(array_merge($site->getArrayCopy(), [
            'deploymentInternalId' => $deployment->getInternalId(),
            'deploymentId' => $deployment->getId(),
        ])));

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($site, Response::MODEL_SITE);
    }
}
