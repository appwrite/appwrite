<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateOrganizationProject';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/organization/projects/:projectId')
            ->desc('Update project')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'projects.update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'projects',
                name: 'updateProject',
                description: <<<EOT
                Update a project by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $name, Response $response, Database $dbForPlatform, Document $team)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($project->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'name' => $name,
            'search' => implode(' ', [$projectId, $name]),
        ]));

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($project, Response::MODEL_PROJECT);
    }
}
