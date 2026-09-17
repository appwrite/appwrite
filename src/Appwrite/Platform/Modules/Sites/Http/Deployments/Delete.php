<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Transaction as TransactionException;
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
            ->label('usage.resource', 'site/{request.siteId}')
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
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('publisherForDeletes')
            ->inject('queueForEvents')
            ->inject('deviceForSites')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        DeletePublisher $publisherForDeletes,
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

        if (
            $deployment->getAttribute('resourceId') !== $site->getId()
            || $deployment->getAttribute('resourceType') !== 'sites'
        ) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        try {
            if (!$dbForProject->deleteDocument('deployments', $deployment->getId())) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from DB');
            }
        } catch (TransactionException) {
            $deploymentExists = !$dbForProject->getDocument('deployments', $deployment->getId())->isEmpty();

            if ($deploymentExists && !$dbForProject->deleteDocument('deployments', $deployment->getId())) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from DB');
            }
        }

        $site = $dbForProject->withTransaction(function () use ($dbForProject, $siteId, $deployment) {
            $site = $dbForProject->getDocument('sites', $siteId, forUpdate: true);
            if ($site->isEmpty()) {
                throw new Exception(Exception::SITE_NOT_FOUND);
            }

            $updates = [];
            if ($site->getAttribute('latestDeploymentId') === $deployment->getId()) {
                $latestDeployment = $dbForProject->findOne('deployments', [
                    Query::equal('resourceType', ['sites']),
                    Query::equal('resourceInternalId', [$site->getSequence()]),
                    Query::orderDesc('$createdAt'),
                    Query::orderDesc('$sequence'),
                ]);
                $updates = [
                    'latestDeploymentCreatedAt' => $latestDeployment->isEmpty() ? null : $latestDeployment->getCreatedAt(),
                    'latestDeploymentInternalId' => $latestDeployment->isEmpty() ? '' : $latestDeployment->getSequence(),
                    'latestDeploymentId' => $latestDeployment->isEmpty() ? '' : $latestDeployment->getId(),
                    'latestDeploymentStatus' => $latestDeployment->isEmpty() ? '' : $latestDeployment->getAttribute('status', ''),
                ];
            }

            if ($site->getAttribute('deploymentId') === $deployment->getId()) {
                $updates['deploymentId'] = '';
                $updates['deploymentInternalId'] = '';
                $updates['deploymentCreatedAt'] = null;
                $updates['deploymentScreenshotDark'] = '';
                $updates['deploymentScreenshotLight'] = '';
            }

            return empty($updates)
                ? $site
                : $dbForProject->updateDocument('sites', $site->getId(), new Document($updates));
        });
        $dbForProject->purgeCachedDocument('sites', $site->getId());

        if (!empty($deployment->getAttribute('sourcePath', ''))) {
            if (!($deviceForSites->delete($deployment->getAttribute('sourcePath', '')))) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from storage');
            }
        }

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_DOCUMENT,
            document: $deployment,
        ));

        $response->noContent();
    }
}
