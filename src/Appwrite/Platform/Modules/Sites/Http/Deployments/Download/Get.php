<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Download;

use Appwrite\Deployment\Deployments;
use Appwrite\Deployment\Token;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Psr\Http\Message\StreamInterface;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getDeploymentDownload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/download')
            ->desc('Get deployment download')
            ->groups(['api', 'sites'])
            // Reachable by privileged callers (console/CLI via sites.read)
            // and by guests holding a valid presigned token (public); the
            // action enforces which one actually applies.
            ->label('scope', ['public', 'sites.read'])
            ->label('usage.resource', 'site/{request.siteId}')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'getDeploymentDownload',
                description: <<<EOT
                Get a site deployment content by its unique ID. The endpoint response return with a 'Content-Disposition: attachment' header that tells the browser to start downloading the file to user downloads directory.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE
                    )
                ],
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
                contentType: ContentType::ANY,
            ))
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Deployment ID.', false, ['dbForProject'])
            ->param('type', 'source', new WhiteList(['source', 'output']), 'Deployment file to download. Can be: "source", "output".', true, enum: new Enum(name: 'DeploymentDownloadType'))
            ->param('token', '', new Text(2048), 'Presigned source-download token for accessing this deployment without a session (jobs-service).', true)
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('deviceForSites')
            ->inject('deviceForBuilds')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        string $type,
        string $token,
        Response $response,
        Request $request,
        Database $dbForProject,
        Device $deviceForSites,
        Device $deviceForBuilds,
        User $user,
        Authorization $authorization,
    ) {
        // Access is granted either to a privileged caller (console/CLI via
        // session or API key) or to a valid presigned token bound to this
        // deployment + artifact type (used by the jobs-service).
        $isPrivileged = $user->isPrivileged($authorization->getRoles()) || $user->isKey($authorization->getRoles());
        if (! $isPrivileged && ! Token::verify($token, $deploymentId, $type)) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Access has been authorized at the endpoint level (privileged caller or
        // presigned token), so read the artifact documents without re-applying
        // document-level permissions (a token holder is not an authed user).
        $site = $authorization->skip(fn () => $dbForProject->getDocument('sites', $siteId));
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (
            $deployment->getAttribute('resourceId') !== $site->getId()
            || $deployment->getAttribute('resourceType') !== 'sites'
        ) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        [$path, $device] = match ($type) {
            'output' => [$deployment->getAttribute('buildPath', ''), $deviceForBuilds],
            'source' => [$deployment->getAttribute('sourcePath', ''), $deviceForSites],
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid deployment download type.'),
        };

        // Builds can write the builds volume, so a local file is opened once and read only through that handle
        $local = $device->getType() === DeviceType::Local;
        $artifact = $local ? Deployments::openArtifact($device, $path) : null;
        if ($local ? $artifact === null : !Deployments::isArtifact($device, $path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $contentType = $type === 'source' ? 'application/gzip' : 'application/octet-stream';
        $filename = $type === 'source'
            ? $deploymentId . '-source.tar.gz'
            : $deploymentId . '-output-' . \basename($path);

        $size = $artifact?->getSize() ?? $device->getFileSize($path);
        $rangeHeader = $request->getHeaderLine('range');

        $response
            ->setContentType($contentType)
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null) {
                $end = $start + MAX_OUTPUT_CHUNK_SIZE - 1;
            }

            // RFC 9110: a last-byte-pos past the end of the file is clamped, not rejected.
            $end = min($end, $size - 1);

            if ($unit !== 'bytes' || $start > $end) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);

            $response->send($this->read($device, $path, $artifact, $start, $end - $start + 1));
            return;
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $this->read(
                        $device,
                        $path,
                        $artifact,
                        $i * MAX_OUTPUT_CHUNK_SIZE,
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($this->read($device, $path, $artifact));
        }
    }

    /**
     * A window of the deployment file: through the handle opened for a local file, by path elsewhere.
     */
    private function read(Device $device, string $path, ?StreamInterface $artifact, int $offset = 0, ?int $length = null): string
    {
        if ($artifact === null) {
            return (string) $device->read($path, $offset, $length);
        }

        $artifact->seek($offset);

        return $length === null ? $artifact->getContents() : $artifact->read($length);
    }
}
