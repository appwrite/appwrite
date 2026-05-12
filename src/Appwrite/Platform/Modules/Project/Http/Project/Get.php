<?php

namespace Appwrite\Platform\Modules\Project\Http\Project;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProject';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project')
            ->httpAlias('/v1/projects/:projectId')
            ->desc('Get project')
            ->groups(['api', 'project'])
            ->label('scope', 'project.read')
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
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $project,
    ) {
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
