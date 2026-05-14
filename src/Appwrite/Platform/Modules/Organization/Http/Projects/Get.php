<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getOrganizationProject';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organizations/:organizationId/projects/:projectId')
            ->desc('Get project')
            ->groups(['api', 'project'])
            ->label('scope', 'organization.projects.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'get',
                description: <<<EOT
                Get a project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('organizationId', '', new UID(), 'Organization unique ID.')
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $organizationId,
        string $projectId,
        Response $response,
        Database $dbForPlatform,
    ) {
        $team = $dbForPlatform->getDocument('teams', $organizationId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($project->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
