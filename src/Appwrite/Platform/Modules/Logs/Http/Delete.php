<?php

namespace Appwrite\Platform\Modules\Logs\Http;

use Appwrite\Extend\Exception;
use Appwrite\Logs;
use Appwrite\Logs\Resource;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteHttpLog';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/logs/:logId')
            ->desc('Delete HTTP log')
            ->groups(['api', 'logs'])
            ->label('scope', 'log.write')
            ->label('sdk', new Method(
                namespace: 'logs',
                group: 'logs',
                name: 'delete',
                description: <<<EOT
                Delete an HTTP log by its unique ID.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
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

        $logs->delete($logId);

        $response->noContent();
    }
}
