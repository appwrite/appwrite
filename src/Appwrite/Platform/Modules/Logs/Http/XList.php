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
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Integer;
use Utopia\Validator\WhiteList;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listHttpLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/logs')
            ->desc('List HTTP logs')
            ->groups(['api', 'logs'])
            ->label('scope', 'log.read')
            ->label('sdk', new Method(
                namespace: 'logs',
                group: 'logs',
                name: 'list',
                description: <<<EOT
                List HTTP logs for a resource. You can filter by resource type and resource ID.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HTTP_LOG_LIST,
                    )
                ]
            ))
            ->param('resource', '', new WhiteList([Resource::Project->value, Resource::Deployment->value]), 'Resource type. Possible values: `project`, `deployment`.')
            ->param('resourceId', '', new \Utopia\Validator\Text(512), 'Resource ID.')
            ->param('limit', 100, new Integer(), 'Maximum number of logs to return. Maximum value is 100.', true)
            ->param('offset', 0, new Integer(), 'Offset value. The default value is 0.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('logs')
            ->callback($this->action(...));
    }

    public function action(
        string $resource,
        string $resourceId,
        int $limit,
        int $offset,
        Response $response,
        Document $project,
        Database $dbForProject,
        Logs $logs,
    ) {
        $resourceEnum = Resource::from($resource);

        switch ($resourceEnum) {
            case Resource::Project:
                if ($resourceId !== $project->getId()) {
                    throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
                }
                break;
            case Resource::Deployment:
                $deployment = $dbForProject->getDocument('deployments', $resourceId);
                if ($deployment->isEmpty()) {
                    throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
                }
                break;
        }

        $limit = \min($limit, 100);
        $offset = \max($offset, 0);

        $results = $logs->list(
            resource: $resourceEnum,
            resourceId: $resourceId,
            limit: $limit,
            offset: $offset,
        );

        $total = $logs->count(
            resource: $resourceEnum,
            resourceId: $resourceId,
        );

        $documents = [];
        foreach ($results as $id => $log) {
            $documents[] = new Document([
                '$id' => $id,
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
            ]);
        }

        $response->dynamic(new Document([
            'httpLogs' => $documents,
            'total' => $total,
        ]), Response::MODEL_HTTP_LOG_LIST);
    }
}
