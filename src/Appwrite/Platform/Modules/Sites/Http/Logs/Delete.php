<?php

namespace Appwrite\Platform\Modules\Sites\Http\Logs;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Logs;
use Appwrite\Logs\Resource;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteLog';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/sites/:siteId/logs/:logId')
            ->desc('Delete log')
            ->groups(['api', 'sites'])
            ->label('scope', 'log.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].logs.[logId].delete')
            ->label('audits.event', 'logs.delete')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'logs',
                name: 'deleteLog',
                description: <<<EOT
                Delete a site log by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('logId', '', new UID(), 'Log ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->inject('logs')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $logId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Authorization $authorization,
        Logs $logs,
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
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

            $logs->delete($logId);

            $queueForEvents
                ->setParam('siteId', $site->getId())
                ->setParam('logId', $logId);

            $response->noContent();

            return;
        }

        $log = $dbForProject->getDocument('executions', $logId);
        if ($log->isEmpty()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        if ($log->getAttribute('resourceType') !== 'sites' && $log->getAttribute('resourceInternalId') !== $site->getSequence()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('executions', $log->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove log from DB');
        }

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('logId', $log->getId())
            ->setPayload($response->output($log, Response::MODEL_EXECUTION));

        $response->noContent();
    }
}
