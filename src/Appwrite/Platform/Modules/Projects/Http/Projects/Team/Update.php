<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects\Team;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Projects\Http\Projects\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectTeam';
    }

    protected function getQueriesValidator(): Validator
    {
        return new Projects();
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/projects/:projectId/team')
            ->desc('Update project team')
            ->groups(['api', 'projects'])
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
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $teamId, Response $response, Database $dbForPlatform)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);
        $team = $dbForPlatform->getDocument('teams', $teamId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $permissions = $this->getPermissions($teamId, $projectId);

        $project
            ->setAttribute('teamId', $teamId)
            ->setAttribute('teamInternalId', $team->getSequence())
            ->setAttribute('$permissions', $permissions);
        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project);

        $installations = $dbForPlatform->find('installations', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($installations as $installation) {
            $installation->setAttribute('$permissions', $permissions);
            $dbForPlatform->updateDocument('installations', $installation->getId(), $installation);
        }

        $repositories = $dbForPlatform->find('repositories', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($repositories as $repository) {
            $repository->setAttribute('$permissions', $permissions);
            $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository);
        }

        $vcsComments = $dbForPlatform->find('vcsComments', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($vcsComments as $vcsComment) {
            $vcsComment->setAttribute('$permissions', $permissions);
            $dbForPlatform->updateDocument('vcsComments', $vcsComment->getId(), $vcsComment);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}