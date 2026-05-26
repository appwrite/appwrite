<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteOrganizationProject';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/organization/projects/:projectId')
            ->desc('Delete project')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'projects.delete')
            ->label('audits.resource', 'project/{request.projectId}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'projects',
                name: 'deleteProject',
                description: <<<EOT
                Delete a project by its unique ID.
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
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('publisherForDeletes')
            ->inject('authorization')
            ->inject('team')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        Response $response,
        Database $dbForPlatform,
        DeletePublisher $publisherForDeletes,
        Authorization $authorization,
        Document $team,
    ) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($project->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('projects', $project->getId()))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $project,
        ));

        $response->noContent();
    }
}
