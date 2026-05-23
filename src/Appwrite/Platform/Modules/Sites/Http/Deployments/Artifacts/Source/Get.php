<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Artifacts\Source;

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
        return 'getSiteDeploymentSourceArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/artifacts/source')
            ->groups(['api', 'sites'])
            ->desc('Get site deployment source artifact')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('token', '', new Text(4096), 'Internal artifact token.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('resourceToken')
            ->inject('deviceForSites')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        string $token,
        Response $response,
        Database $dbForProject,
        Document $project,
        Document $resourceToken,
        Device $deviceForSites
    ) {
        $this->verifyArtifactToken($resourceToken, RESOURCE_TYPE_SITES, $siteId, $deploymentId, 'source');

        $site = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('sites', $siteId));
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $path = $deployment->getAttribute('sourcePath', '');
        if (!$deviceForSites->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $response
            ->setContentType('application/gzip')
            ->addHeader('Cache-Control', 'no-store')
            ->addHeader('Content-Disposition', 'attachment; filename="' . $deploymentId . '-source.tar.gz"');

        $size = $deviceForSites->getFileSize($path);
        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForSites->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
            return;
        }

        $response->send($deviceForSites->read($path));
    }

    private function verifyArtifactToken(Document $resourceToken, string $resourceType, string $resourceId, string $deploymentId, string $purpose): void
    {
        if ($resourceToken->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid build artifact token.');
        }

        if (
            $resourceToken->getAttribute('resourceType') !== $resourceType ||
            $resourceToken->getAttribute('resourceId') !== $resourceId ||
            $resourceToken->getAttribute('deploymentId') !== $deploymentId ||
            $resourceToken->getAttribute('purpose') !== $purpose
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Build artifact token mismatch.');
        }
    }
}
