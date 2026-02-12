<?php

namespace Appwrite\Platform\Modules\Sites\Http\Logs;

use Appwrite\Extend\Exception;
use Appwrite\Logs;
use Appwrite\Logs\Resource;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getLog';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/logs/:logId')
            ->desc('Get log')
            ->groups(['api', 'sites'])
            ->label('scope', 'log.read')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'logs',
                name: 'getLog',
                description: <<<EOT
                Get a site request log by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EXECUTION,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('logId', '', new UID(), 'Log ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('logs')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $logId,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
        Logs $logs,
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty() || !$site->getAttribute('enabled')) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if (System::getEnv('FEATURE_LOGS', 'enabled') === 'enabled') {
            $log = $logs->get($logId);

            if ($log === null) {
                throw new Exception(Exception::LOG_NOT_FOUND);
            }

            if ($log->resource !== Resource::Deployment) {
                throw new Exception(Exception::LOG_NOT_FOUND);
            }

            $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $log->resourceId));

            if ($deployment->isEmpty() || $deployment->getAttribute('resourceId') !== $siteId) {
                throw new Exception(Exception::LOG_NOT_FOUND);
            }

            $response->dynamic(new Document([
                '$id' => $logId,
                '$createdAt' => \date('Y-m-d\TH:i:s.vP', (int) $log->timestamp),
                '$permissions' => [],
                'resourceId' => $siteId,
                'deploymentId' => $log->resourceId,
                'trigger' => 'http',
                'status' => $log->responseStatusCode >= 500 ? 'failed' : 'completed',
                'requestMethod' => $log->requestMethod->value,
                'requestPath' => $log->requestPath,
                'requestHeaders' => [],
                'responseStatusCode' => $log->responseStatusCode,
                'responseBody' => '',
                'responseHeaders' => [],
                'logs' => '',
                'errors' => '',
                'duration' => $log->durationSeconds,
            ]), Response::MODEL_EXECUTION);

            return;
        }

        $log = $dbForProject->getDocument('executions', $logId);

        if ($log->getAttribute('resourceType') !== 'sites' && $log->getAttribute('resourceInternalId') !== $site->getSequence()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        if ($log->isEmpty()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        $response->dynamic($log, Response::MODEL_EXECUTION);
    }
}
