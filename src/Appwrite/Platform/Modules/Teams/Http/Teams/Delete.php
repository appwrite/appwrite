<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams;

use Appwrite\Event\Delete as DeleteEvent;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Workers\Deletes;
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
        return 'deleteTeam';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/teams/:teamId')
            ->desc('Delete team')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].delete')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'team.delete')
            ->label('audits.resource', 'team/{request.teamId}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'delete',
                description: '/docs/references/teams/delete-team.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->inject('response')
            ->inject('getProjectDB')
            ->inject('dbForProject')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(string $teamId, Response $response, callable $getProjectDB, Database $dbForProject, DeleteEvent $queueForDeletes, Event $queueForEvents, Document $project)
    {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('teams', $teamId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove team from DB');
        }

        // Sync delete
        $deletes = new Deletes();
        $deletes->deleteMemberships($getProjectDB, $team, $project);

        // Async delete
        if ($project->getId() === 'console') {
            $queueForDeletes
                ->setType(DELETE_TYPE_TEAM_PROJECTS)
                ->setDocument($team)
                ->trigger();
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($team);

        $queueForEvents
            ->setParam('teamId', $team->getId())
            ->setPayload($response->output($team, Response::MODEL_TEAM))
        ;

        $response->noContent();
    }
}