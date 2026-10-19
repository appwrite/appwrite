<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Sessions;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteProjectSessions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/sessions')
            ->httpAlias('/v1/projects/:projectId/sessions')
            ->desc('Delete all project sessions')
            ->groups(['api', 'project'])
            ->label('scope', ['users.write', 'sessions.write'])
            ->label('event', 'projects.[projectId].sessions.delete')
            ->label('audits.event', 'project.sessions.delete')
            ->label('audits.resource', 'project/{project.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'sessions',
                name: 'deleteSessions',
                description: <<<'EOT'
                Delete all sessions in the project. Any session created before this request will be invalidated immediately: users will be signed out of every active client (web, mobile, server SDKs, JWTs), and will be able to sign in again afterwards. API keys are not affected by this action.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Authorization $authorization,
        User $authUser,
    ): void {
        if (! $authUser->isKey($authorization->getRoles()) && ! $authUser->isPrivileged($authorization->getRoles())) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE);
        }

        $epoch = DateTime::formatTz(DateTime::now());

        $auths = $project->getAttribute('auths', []);
        $auths['sessionsValidAfter'] = $epoch;

        $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_SESSIONS,
            document: $project,
            datetime: $epoch,
        ));

        $queueForEvents->setParam('projectId', $project->getId());

        $response->noContent();
    }
}
