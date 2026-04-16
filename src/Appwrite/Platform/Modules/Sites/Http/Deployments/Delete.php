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
use Utopia\Database\Query;
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
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].deployments.[deploymentId].delete')
            ->label('audits.event', 'deployment.delete')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'deleteDeployment',
                description: <<<EOT
                Delete a site deployment by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
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
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        DeleteEvent $queueForDeletes,
        Event $queueForEvents,
        Device $deviceForSites
    ) {
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

        if (!empty($deployment->getAttribute('sourcePath', ''))) {
            if (!($deviceForSites->delete($deployment->getAttribute('sourcePath', '')))) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from storage');
            }
        }

        if ($site->getAttribute('latestDeploymentId') === $deployment->getId()) {
            $latestDeployment = $dbForProject->findOne('deployments', [
                Query::equal('resourceType', ['sites']),
                Query::equal('resourceInternalId', [$site->getSequence()]),
                Query::orderDesc('$createdAt'),
            ]);
            $site = $dbForProject->updateDocument(
                'sites',
                $site->getId(),
                $site
                ->setAttribute('latestDeploymentCreatedAt', $latestDeployment->isEmpty() ? '' : $latestDeployment->getCreatedAt())
                ->setAttribute('latestDeploymentInternalId', $latestDeployment->isEmpty() ? '' : $latestDeployment->getSequence())
                ->setAttribute('latestDeploymentId', $latestDeployment->isEmpty() ? '' : $latestDeployment->getId())
                ->setAttribute('latestDeploymentStatus', $latestDeployment->isEmpty() ? '' : $latestDeployment->getAttribute('status', ''))
            );
        }

        if ($site->getAttribute('deploymentId') === $deployment->getId()) { // Reset site deployment
            $site = $dbForProject->updateDocument('sites', $site->getId(), new Document(array_merge($site->getArrayCopy(), [
                'deploymentId' => '',
                'deploymentInternalId' => '',
                'deploymentScreenshotDark' => '',
                'deploymentScreenshotLight' => '',
                'deploymentCreatedAt' => '',
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
