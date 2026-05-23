<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Artifacts\Build;

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
        return 'updateSiteDeploymentBuildArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/artifacts/build')
            ->groups(['api', 'sites'])
            ->desc('Update site deployment build artifact')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
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
        string $siteId,
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
            $resourceToken->getAttribute('resourceType') !== RESOURCE_TYPE_SITES ||
            $resourceToken->getAttribute('resourceId') !== $siteId ||
            $resourceToken->getAttribute('deploymentId') !== $deploymentId ||
            $resourceToken->getAttribute('purpose') !== 'build'
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }

        $site = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $siteId));
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $this->uploadBuildArtifact(
            deploymentId: $deploymentId,
            project: $project,
            resource: $site,
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
