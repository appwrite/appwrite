<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
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
        return 'getProjectById';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId')
            ->desc('Get project')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'projects',
                name: 'get',
                description: <<<'EOT'
                Get a project by its unique ID.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    ),
                ]
            ))
            ->param('projectId', '', new UID, 'Project unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, Response $response, Database $dbForPlatform)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
