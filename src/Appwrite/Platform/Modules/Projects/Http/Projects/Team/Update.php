<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects\Team;

use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Projects\Http\Projects\Action;
use Appwrite\Platform\Modules\Projects\Move;
use Appwrite\Screenshots\Access;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;
    use Move;

    public static function getName()
    {
        return 'updateProjectTeam';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/projects/:projectId/team')
            ->desc('Update project team')
            ->groups(['api', 'projects'])
            ->label('audits.event', 'projects.team.update')
            ->label('audits.resource', 'project/{request.projectId}')
            ->label('scope', 'projects.write')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'projects',
                name: 'updateTeam',
                description: '/docs/references/projects/update-team.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ]
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('teamId', '', new UID(), 'Team ID of the team to transfer project to.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('targetUser')
            ->inject('queueForRealtime')
            ->inject('getProjectDB')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $teamId, Response $response, Database $dbForPlatform, User $targetUser, Realtime $queueForRealtime, callable $getProjectDB): void
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);
        $team = $dbForPlatform->getDocument('teams', $teamId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if (
            !$targetUser->isOwner($dbForPlatform, $project->getAttribute('teamInternalId', ''))
            || !$targetUser->isOwner($dbForPlatform, $team->getSequence())
        ) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Transferring a project requires the owner role on both the current and the destination organization.');
        }

        $permissions = $this->getPermissions($teamId, $projectId);

        // All or nothing: a failure part-way leaves the project and its VCS records with the organization they were in
        $project = $dbForPlatform->withTransaction(fn () => $this->moveProject($dbForPlatform, $project, $team, $permissions));

        $this->announceMove($dbForPlatform, $project, $team, $queueForRealtime);

        (new Access($dbForPlatform))->grant($getProjectDB($project), $project);

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
