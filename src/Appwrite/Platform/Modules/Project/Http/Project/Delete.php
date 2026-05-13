<?php

namespace Appwrite\Platform\Modules\Project\Http\Project;

use Appwrite\Event\Delete as DeleteQueue;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteProject';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project')
            ->httpAlias('/v1/projects/:projectId')
            ->desc('Delete project')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('audits.event', 'project.delete')
            ->label('audits.resource', 'project/{project.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'delete',
                description: <<<EOT
                Delete a project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('queueForDeletes')
            ->inject('authorization')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Database $dbForPlatform,
        DeleteQueue $queueForDeletes,
        Authorization $authorization,
        Document $project,
    ) {
        $queueForDeletes
            ->setProject($project)
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($project);

        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('projects', $project->getId()))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project from DB');
        }

        $response->noContent();
    }
}
