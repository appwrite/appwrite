<?php

namespace Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Source;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getDeploymentSourceArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/deployments/:deploymentId/artifacts/source')
            ->groups(['api', 'deployments'])
            ->desc('Get deployment source artifact')
            ->label('scope', 'public')
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('token', '', new Text(4096), 'Internal artifact token.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('resourceToken')
            ->inject('deviceForFunctions')
            ->inject('deviceForSites')
            ->callback($this->action(...));
    }

    public function action(
        string $deploymentId,
        string $token,
        Response $response,
        Database $dbForProject,
        Document $resourceToken,
        Device $deviceForFunctions,
        Device $deviceForSites
    ) {
        if ($resourceToken->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid build artifact token.');
        }

        if (
            $resourceToken->getAttribute('deploymentId') !== $deploymentId ||
            $resourceToken->getAttribute('purpose') !== 'source'
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }

        $resourceType = $resourceToken->getAttribute('resourceType');
        $resourceId = $resourceToken->getAttribute('resourceId');

        if ($resourceType === RESOURCE_TYPE_FUNCTIONS) {
            $resource = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('functions', $resourceId));
            $device = $deviceForFunctions;
            $notFound = Exception::FUNCTION_NOT_FOUND;
        } elseif ($resourceType === RESOURCE_TYPE_SITES) {
            $resource = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $resourceId));
            $device = $deviceForSites;
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

        $path = $deployment->getAttribute('sourcePath', '');
        if (!$device->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $response
            ->setContentType('application/gzip')
            ->addHeader('Cache-Control', 'no-store')
            ->addHeader('Content-Disposition', 'attachment; filename="' . $deploymentId . '-source.tar.gz"');

        $size = $device->getFileSize($path);
        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $device->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
            return;
        }

        $response->send($device->read($path));
    }

}
