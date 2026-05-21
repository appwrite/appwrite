<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Artifacts\Build;

use Appwrite\Builds\OrchestratorToken;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
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
            ->inject('deviceForBuilds')
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
        Device $deviceForBuilds
    ) {
        $token = $token ?: $request->getQuery('token', '');
        OrchestratorToken::verify($token, $project->getId(), $siteId, $deploymentId, 'build');

        $site = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $siteId));
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $tmp = \tempnam(\sys_get_temp_dir(), 'appwrite-build-');
        if ($tmp === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed creating temporary build artifact file.');
        }

        \file_put_contents($tmp, $request->getRawPayload());

        $metadata = ['content_type' => 'application/gzip'];
        $path = $deviceForBuilds->getPath($deploymentId . '.tar.gz');
        $uploaded = $deviceForBuilds->upload($tmp, $path, 1, 1, $metadata);
        @\unlink($tmp);

        if ($uploaded < 1) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed storing build artifact.');
        }

        $size = $deviceForBuilds->getFileSize($path);
        $dbForProject->getAuthorization()->skip(fn () => $dbForProject->updateDocument('deployments', $deploymentId, new Document([
            'buildPath' => $path,
            'buildSize' => $size,
            'totalSize' => $deployment->getAttribute('sourceSize', 0) + $size,
        ])));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->json([
                'path' => $path,
                'size' => $size,
            ]);
    }
}
