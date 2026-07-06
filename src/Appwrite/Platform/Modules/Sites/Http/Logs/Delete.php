<?php

namespace Appwrite\Platform\Modules\Sites\Http\Logs;

use Appwrite\Bus\Events\SiteLogDeleted;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
            ->label('audits.event', 'logs.delete')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('usage.resource', 'site/{request.siteId}')
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
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('logId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Log ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('bus')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(string $siteId, string $logId, Response $response, Database $dbForProject, Bus $bus, Document $project, Document $actor)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
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

        $bus->dispatch(new SiteLogDeleted($log, $site->getId(), $project, $actor));

        $response->noContent();
    }
}
