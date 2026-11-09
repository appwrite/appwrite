<?php

namespace Appwrite\Platform\Modules\Account\Http\Account;

use Appwrite\Deletes\Identities as DeleteIdentities;
use Appwrite\Deletes\Targets as DeleteTargets;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\Presences\State as PresenceState;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'delete';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account')
            ->desc('Delete account')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('audits.event', 'user.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'delete',
                description: '/docs/references/account/delete.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->inject('targetUser')
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('authorization')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(
        User $targetUser,
        Document $project,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Authorization $authorization,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
        Context $usage,
    ): void {
        if ($targetUser->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if ($project->getId() === 'console') {
            $memberships = $targetUser->getAttribute('memberships', []);
            foreach ($memberships as $membership) {
                if (!$membership->getAttribute('confirm', false)) {
                    continue;
                }

                $team = $dbForProject->getDocument('teams', $membership->getAttribute('teamId'));
                if ($team->isEmpty()) {
                    continue;
                }

                // Team is left as-is — we don't promote non-owner members to owner.
                // Orphan teams are cleaned up later by Cloud's inactive project cleanup.
            }
        }

        // A failure rolls back to an intact account to retry; the rest is queued only once these rows are gone.
        $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $targetUser) {
            $dbForProject->deleteDocument('users', $targetUser->getId());
            DeleteIdentities::delete($dbForProject, Query::equal('userInternalId', [$targetUser->getSequence()]));
            DeleteTargets::delete($dbForProject, Query::equal('userInternalId', [$targetUser->getSequence()]));
            $dbForProject->deleteDocuments('sessions', [Query::equal('userInternalId', [$targetUser->getSequence()])]);
        }));

        // The delete's own purge ran inside this transaction, so a read before the commit may have re-cached the user.
        $dbForProject->purgeCachedDocument('users', $targetUser->getId());

        try {
            $mqtt->revoke($project->getId(), $targetUser->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        (new PresenceState())->deleteForUser($dbForProject, $project, $targetUser, $queueForRealtime, $usage);

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $targetUser,
        ));

        $queueForEvents
            ->setParam('userId', $targetUser->getId())
            ->setPayload($response->output($targetUser, Response::MODEL_USER));

        $response->noContent();
    }
}
