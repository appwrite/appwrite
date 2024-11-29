<?php

namespace Appwrite\Platform\Modules\Sites\Http\Logs;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class DeleteLog extends Base
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
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'deleteLog')
            ->label('sdk.description', '/docs/references/sites/delete-log.md') // TODO: add this file
            ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
            ->label('sdk.response.model', Response::MODEL_NONE)
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('logId', '', new UID(), 'Log ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $logId, Response $response, Database $dbForProject, Event $queueForEvents)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $log = $dbForProject->getDocument('executions', $logId);
        if ($log->isEmpty()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        if ($log->getAttribute('resourceType') !== 'sites' && $log->getAttribute('resourceId') !== $site->getId()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('executions', $log->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove log from DB');
        }

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('logId', $log->getId())
            ->setPayload($response->output($log, Response::MODEL_EXECUTION)); // TODO: Update model

        $response->noContent();
    }
}
