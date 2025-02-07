<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Event\Delete as DeleteEvent;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId')
            ->desc('Delete deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('event', 'sites.[siteId].deployments.[deploymentId].delete')
            ->label('audits.event', 'deployment.delete')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'deleteDeployment',
                description: <<<EOT
                Delete a site deployment by its unique ID.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->inject('deviceForSites')
            ->inject('deviceForFunctions') //TODO: remove it later
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Response $response, Database $dbForProject, DeleteEvent $queueForDeletes, Event $queueForEvents, Device $deviceForSites, Device $deviceForFunctions)
    {
        $site = $dbForProject->getDocument('sites', $siteId);
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('deployments', $deployment->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from DB');
        }

        if (!empty($deployment->getAttribute('path', ''))) {
            if (!($deviceForFunctions->delete($deployment->getAttribute('path', '')))) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from storage');
            }
        }

        if ($site->getAttribute('deployment') === $deployment->getId()) { // Reset site deployment
            $site = $dbForProject->updateDocument('sites', $site->getId(), new Document(array_merge($site->getArrayCopy(), [
                'deployment' => '',
                'deploymentInternalId' => '',
            ])));
        }

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($deployment);

        $response->noContent();
    }
}
