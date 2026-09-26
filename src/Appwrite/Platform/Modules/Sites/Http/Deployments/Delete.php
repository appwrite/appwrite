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
use Utopia\Lock\Exception\Contention;
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
            ->inject('project')
            ->inject('dbForProject')
            ->inject('publisherForDeletes')
            ->inject('queueForEvents')
            ->inject('deviceForSites')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Response $response,
        Document $project,
        Database $dbForProject,
        DeletePublisher $publisherForDeletes,
        Event $queueForEvents,
        Device $deviceForSites,
        callable $locks
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

        // The Console deletes deployments in parallel, so repointing must not pick one that another request is deleting.
        try {
            $site = $locks('sites:deployments:' . $project->getId() . ':' . $siteId, 30, function () use ($dbForProject, $siteId, $deployment) {
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

                $site = $dbForProject->getDocument('sites', $siteId);

                if ($site->getAttribute('latestDeploymentId') === $deployment->getId()) {
                    $latestDeployment = $dbForProject->findOne('deployments', [
                        Query::equal('resourceType', ['sites']),
                        Query::equal('resourceInternalId', [$site->getSequence()]),
                        Query::orderDesc('$createdAt'),
                    ]);
                    $site = $dbForProject->updateDocument(
                        'sites',
                        $site->getId(),
                        new Document([
                            'latestDeploymentCreatedAt' => $latestDeployment->isEmpty() ? null : $latestDeployment->getCreatedAt(),
                            'latestDeploymentInternalId' => $latestDeployment->isEmpty() ? '' : $latestDeployment->getSequence(),
                            'latestDeploymentId' => $latestDeployment->isEmpty() ? '' : $latestDeployment->getId(),
                            'latestDeploymentStatus' => $latestDeployment->isEmpty() ? '' : $latestDeployment->getAttribute('status', ''),
                        ])
                    );
                }

                if ($site->getAttribute('deploymentId') === $deployment->getId()) { // Reset site deployment
                    $site = $dbForProject->updateDocument('sites', $site->getId(), new Document([
                        'deploymentId' => '',
                        'deploymentInternalId' => '',
                        'deploymentScreenshotDark' => '',
                        'deploymentScreenshotLight' => '',
                        'deploymentCreatedAt' => null,
                    ]));
                }

                return $site;
            }, 10.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment deletion is busy. Try again.');
        }

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
