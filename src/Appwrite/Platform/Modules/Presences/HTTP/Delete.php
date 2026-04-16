<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deletePresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/presences/:presenceId')
            ->desc('Delete presence')
            ->groups(['api', 'presences'])
            ->label('scope', 'users.write')
            ->label('event', 'presences.[presenceId].delete')
            ->label('audits.event', 'presence.delete')
            ->label('audits.resource', 'presence/{request.presenceId}')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'delete',
                description: 'Delete a presence log by its unique ID.',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE,
            ))
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $presenceId, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $presence = $dbForProject->getDocument('presenceLogs', $presenceId);

        if ($presence->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $dbForProject->deleteDocument('presenceLogs', $presenceId);

        $queueForEvents
            ->setParam('presenceId', $presence->getId())
            ->setPayload($response->output($presence, Response::MODEL_PRESENCE));

        $response->noContent();
    }
}
