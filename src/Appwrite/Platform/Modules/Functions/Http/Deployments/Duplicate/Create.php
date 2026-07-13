<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Duplicate;

use Appwrite\Deployment\Backend;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDuplicateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/deployments/duplicate')
            ->httpAlias('/v1/functions/:functionId/deployments/:deploymentId/build')
            ->httpAlias('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
            ->desc('Create duplicate deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'createDuplicateDeployment',
                description: <<<EOT
                Create a new build for an existing function deployment. This endpoint allows you to rebuild a deployment with the updated function configuration, including its entrypoint and build commands if they have been modified. The build process will be queued and executed asynchronously. The original deployment's code will be preserved and used for the new build.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ]
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('buildId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Build unique ID.', true, ['dbForProject']) // added as optional param for backward compatibility
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deployments')
            ->inject('deviceForFunctions')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        string $buildId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Backend $deployments,
        Device $deviceForFunctions,
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $path = $deployment->getAttribute('sourcePath');
        if (empty($path) || !$deviceForFunctions->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $deploymentId = ID::unique();

        $destination = $deviceForFunctions->getPath($deploymentId . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
        $deviceForFunctions->transfer($path, $destination, $deviceForFunctions);

        // Cloning the source deployment's attributes onto the new one, with
        // its own $id and no $sequence, tells the service to create it fresh
        // rather than update the deployment being duplicated.
        $deployment->removeAttribute('$sequence');
        $deployment->setAttributes([
            '$id' => $deploymentId,
            'sourcePath' => $destination,
            'totalSize' => $deployment->getAttribute('sourceSize', 0),
            'entrypoint' => $function->getAttribute('entrypoint'),
            'buildCommands' => $function->getAttribute('commands', ''),
            'startCommand' => $function->getAttribute('startCommand', ''),
            'buildStartedAt' => null,
            'buildEndedAt' => null,
            'buildDuration' => null,
            'buildSize' => null,
            'buildPath' => '',
            'buildLogs' => '',
        ]);

        $deployment = $deployments->createFromUpload($function, $deployment);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}
