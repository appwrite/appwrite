<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments\Artifacts\Build;

use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Http\Deployments\Artifacts\Build\ChunkedBuildArtifact;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;
    use ChunkedBuildArtifact;

    public static function getName()
    {
        return 'updateDeploymentBuildArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/functions/:functionId/deployments/:deploymentId/artifacts/build')
            ->groups(['api', 'functions'])
            ->desc('Update deployment build artifact')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('token', '', new Text(4096), 'Internal artifact token.', true)
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('resourceToken')
            ->inject('deviceForBuilds')
            ->inject('publisherForBuilds')
            ->inject('cache')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        string $token,
        Response $response,
        Request $request,
        Database $dbForProject,
        Document $project,
        Document $resourceToken,
        Device $deviceForBuilds,
        BuildPublisher $publisherForBuilds,
        Cache $cache,
        callable $locks
    ) {
        if ($resourceToken->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid build artifact token.');
        }

        if (
            $resourceToken->getAttribute('resourceType') !== RESOURCE_TYPE_FUNCTIONS ||
            $resourceToken->getAttribute('resourceId') !== $functionId ||
            $resourceToken->getAttribute('deploymentId') !== $deploymentId ||
            $resourceToken->getAttribute('purpose') !== 'build'
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }

        $function = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('functions', $functionId));
        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $this->uploadBuildArtifact(
            deploymentId: $deploymentId,
            project: $project,
            resource: $function,
            deployment: $deployment,
            request: $request,
            response: $response,
            dbForProject: $dbForProject,
            deviceForBuilds: $deviceForBuilds,
            publisherForBuilds: $publisherForBuilds,
            cache: $cache,
            locks: $locks
        );
    }

}
