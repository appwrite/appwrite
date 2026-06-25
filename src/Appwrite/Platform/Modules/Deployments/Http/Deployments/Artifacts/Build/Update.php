<?php

namespace Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Build;

use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateDeploymentBuildArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/deployments/:deploymentId/artifacts/build')
            ->groups(['api', 'deployments'])
            ->desc('Update deployment build artifact')
            ->label('scope', 'public')
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('token', '', new Text(4096), 'Internal artifact token.', true)
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('resourceToken')
            ->inject('deviceForBuilds')
            ->inject('publisherForBuilds')
            ->inject('cache')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $deploymentId,
        string $token,
        Response $response,
        Request $request,
        Database $dbForProject,
        Database $dbForPlatform,
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
            $resourceToken->getAttribute('deploymentId') !== $deploymentId ||
            $resourceToken->getAttribute('purpose') !== 'build'
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }

        $project = $dbForPlatform->getDocument('projects', $resourceToken->getAttribute('projectId'));
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND, 'Project not found.', 404);
        }

        $resourceType = $resourceToken->getAttribute('resourceType');
        $resourceId = $resourceToken->getAttribute('resourceId');

        if ($resourceType === RESOURCE_TYPE_FUNCTIONS) {
            $resource = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('functions', $resourceId));
            $notFound = Exception::FUNCTION_NOT_FOUND;
        } elseif ($resourceType === RESOURCE_TYPE_SITES) {
            $resource = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $resourceId));
            $notFound = Exception::SITE_NOT_FOUND;
        } else {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }

        if ($resource->isEmpty()) {
            throw new Exception($notFound);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $resource->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $this->uploadBuildArtifact(
            deploymentId: $deploymentId,
            project: $project,
            resource: $resource,
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
