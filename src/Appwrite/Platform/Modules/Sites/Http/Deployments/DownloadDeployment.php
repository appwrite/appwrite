<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Swoole\Request;

class DownloadDeployment extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'downloadDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/deployments/:deploymentId/download')
            ->desc('Download deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'getDeploymentDownload')
            ->label('sdk.description', '/docs/references/sites/get-deployment-download.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', '*/*')
            ->label('sdk.methodType', 'location')
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('deviceForSites')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $deploymentId, Response $response, Request $request, Database $dbForProject, Device $deviceForSites)
    {
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

        $path = $deployment->getAttribute('path', '');
        if (!$deviceForSites->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $response
            ->setContentType('application/gzip')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $deploymentId . '.tar.gz"');

        $size = $deviceForSites->getFileSize($path);
        $rangeHeader = $request->getHeader('range');

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

            $response->send($deviceForSites->read($path, $start, ($end - $start + 1)));
        }

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
        } else {
            $response->send($deviceForSites->read($path));
        }
    }
}
