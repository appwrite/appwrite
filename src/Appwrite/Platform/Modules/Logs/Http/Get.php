<?php

namespace Appwrite\Platform\Modules\Logs\Http;

use Appwrite\Extend\Exception;
use Appwrite\Logs;
use Appwrite\Logs\Resource;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getHttpLog';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/logs/:logId')
            ->desc('Get HTTP log')
            ->groups(['api', 'logs'])
            ->label('scope', 'log.read')
            ->label('sdk', new Method(
                namespace: 'logs',
                group: 'logs',
                name: 'get',
                description: <<<EOT
                Get an HTTP log by its unique ID.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HTTP_LOG,
                    )
                ]
            ))
            ->param('logId', '', new UID(), 'Log ID.')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('logs')
            ->callback($this->action(...));
    }

    public function action(
        string $logId,
        Response $response,
        Document $project,
        Database $dbForProject,
        Logs $logs,
    ) {
        $log = $logs->get($logId);

        if ($log === null) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        switch ($log->resource) {
            case Resource::Project:
                if ($log->resourceId !== $project->getId()) {
                    throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
                }
                break;
            case Resource::Deployment:
                $deployment = $dbForProject->getDocument('deployments', $log->resourceId);
                if ($deployment->isEmpty()) {
                    throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
                }
                break;
        }

        $response->dynamic(new Document([
            '$id' => $logId,
            'resource' => $log->resource->value,
            'resourceId' => $log->resourceId,
            'durationSeconds' => $log->durationSeconds,
            'requestMethod' => $log->requestMethod->value,
            'requestScheme' => $log->requestScheme,
            'requestHost' => $log->requestHost,
            'requestPath' => $log->requestPath,
            'requestQuery' => $log->requestQuery,
            'requestSizeBytes' => $log->requestSizeBytes,
            'responseStatusCode' => $log->responseStatusCode,
            'responseSizeBytes' => $log->responseSizeBytes,
        ]), Response::MODEL_HTTP_LOG);
    }
}
