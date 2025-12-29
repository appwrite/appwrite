<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Download;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Swoole\Request;
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
            ->label('scope', 'sites.read')
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
                contentType: ContentType::ANY,
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->param('type', 'source', new WhiteList(['source', 'output']), 'Deployment file to download. Can be: "source", "output".', true)
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('deviceForSites')
            ->inject('deviceForBuilds')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        string $type,
        Response $response,
        Request $request,
        Database $dbForProject,
        Device $deviceForSites,
        Device $deviceForBuilds
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);
        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        switch ($type) {
            case 'output':
                $path = $deployment->getAttribute('buildPath', '');
                $device = $deviceForBuilds;
                break;
            case 'source':
                $path = $deployment->getAttribute('sourcePath', '');
                $device = $deviceForSites;
                break;
        }

        if (!$device->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $size = $device->getFileSize($path);
        $rangeHeader = $request->getHeader('range');

        $response
            ->setContentType('application/gzip')
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $deploymentId . '-' . $type . '.tar.gz"');

        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null) {
                $end = min(($start + MAX_OUTPUT_CHUNK_SIZE - 1), ($size - 1));
            }

            if ($unit !== 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);

            $response->send($device->read($path, $start, ($end - $start + 1)));
            return;
        }

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
        } else {
            $response->send($device->read($path));
        }
    }
}
