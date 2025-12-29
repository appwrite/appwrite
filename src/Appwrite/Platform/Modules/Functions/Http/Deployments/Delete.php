<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments;

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
            ->setHttpPath('/v1/functions/:functionId/deployments/:deploymentId')
            ->desc('Delete deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].deployments.[deploymentId].delete')
            ->label('audits.event', 'deployment.delete')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'deleteDeployment',
                description: <<<EOT
                Delete a code deployment by its unique ID.
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
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->inject('deviceForFunctions')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        Response $response,
        Database $dbForProject,
        DeleteEvent $queueForDeletes,
        Event $queueForEvents,
        Device $deviceForFunctions
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('deployments', $deployment->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from DB');
        }

        if (!empty($deployment->getAttribute('sourcePath', ''))) {
            if (!($deviceForFunctions->delete($deployment->getAttribute('sourcePath', '')))) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from storage');
            }
        }

        if ($function->getAttribute('latestDeploymentId') === $deployment->getId()) {
            $latestDeployment = $dbForProject->findOne('deployments', [
                Query::equal('resourceType', ['functions']),
                Query::equal('resourceInternalId', [$function->getSequence()]),
                Query::orderDesc('$createdAt'),
            ]);
            $function = $dbForProject->updateDocument(
                'functions',
                $function->getId(),
                $function
                ->setAttribute('latestDeploymentCreatedAt', $latestDeployment->isEmpty() ? '' : $latestDeployment->getCreatedAt())
                ->setAttribute('latestDeploymentInternalId', $latestDeployment->isEmpty() ? '' : $latestDeployment->getSequence())
                ->setAttribute('latestDeploymentId', $latestDeployment->isEmpty() ? '' : $latestDeployment->getId())
                ->setAttribute('latestDeploymentStatus', $latestDeployment->isEmpty() ? '' : $latestDeployment->getAttribute('status', ''))
            );
        }

        if ($function->getAttribute('deploymentId') === $deployment->getId()) { // Reset function deployment
            $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                'deploymentId' => '',
                'deploymentInternalId' => '',
                'deploymentCreatedAt' => '',
            ])));
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($deployment);

        $response->noContent();
    }
}
