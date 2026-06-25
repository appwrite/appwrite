<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Presences\State as PresenceState;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;

class Delete extends PlatformAction
{
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
            ->label('scope', 'presences.write')
            ->label('event', 'presences.[presenceId].delete')
            ->label('audits.event', 'presence.delete')
            ->label('audits.resource', 'presence/{request.presenceId}')
            ->label('usage.resource', 'presence/{request.presenceId}')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'delete',
                desc: 'Delete presence',
                description: '/docs/references/presences/delete.md',
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
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(string $presenceId, Response $response, Database $dbForProject, Event $queueForEvents, Context $usage): void
    {
        $presence = $dbForProject->getDocument('presenceLogs', $presenceId);

        if ($presence->isEmpty()) {
            throw new Exception(Exception::PRESENCE_NOT_FOUND);
        }

        try {
            $dbForProject->deleteDocument('presenceLogs', $presenceId);
        } catch (ConflictException) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT);
        } catch (RestrictedException) {
            throw new Exception(Exception::DOCUMENT_DELETE_RESTRICTED);
        }

        (new PresenceState())->purgeListCache($dbForProject);

        $usage->addMetric(METRIC_USERS_PRESENCE, -1);

        $queueForEvents
            ->setParam('presenceId', $presence->getId())
            ->setPayload($response->output($presence, Response::MODEL_PRESENCE));

        $response->noContent();
    }
}
