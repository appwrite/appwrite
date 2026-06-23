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
use Utopia\Database\Document;
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

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'teamId' => $teamId,
            'teamInternalId' => $team->getSequence(),
            '$permissions' => $permissions,
        ]));

        $installations = $dbForPlatform->find('installations', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($installations as $installation) {
            $dbForPlatform->updateDocument('installations', $installation->getId(), new Document(['$permissions' => $permissions]));
        }

        $repositories = $dbForPlatform->find('repositories', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($repositories as $repository) {
            $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['$permissions' => $permissions]));
        }

        $vcsComments = $dbForPlatform->find('vcsComments', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($vcsComments as $vcsComment) {
            $dbForPlatform->updateDocument('vcsComments', $vcsComment->getId(), new Document(['$permissions' => $permissions]));
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
