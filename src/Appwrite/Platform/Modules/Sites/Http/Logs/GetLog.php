<?php

namespace Appwrite\Platform\Modules\Sites\Http\Logs;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class GetLog extends Base
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
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'getLog')
            ->label('sdk.description', '/docs/references/sites/get-log.md') // TODO: add this file
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_EXECUTION)
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('logId', '', new UID(), 'Log ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $logId, Response $response, Database $dbForProject)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty() || !$site->getAttribute('enabled')) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $log = $dbForProject->getDocument('executions', $logId);

        if ($log->getAttribute('resourceType') !== 'sites' && $log->getAttribute('resourceInternalId') !== $site->getInternalId()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        if ($log->isEmpty()) {
            throw new Exception(Exception::LOG_NOT_FOUND);
        }

        $response->dynamic($log, Response::MODEL_EXECUTION); //TODO: Change to model log, but model log already exists - decide what to do
    }
}
