<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Passwords;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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
        return 'deleteProjectPasswords';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/passwords')
            ->desc('Delete all project passwords')
            ->groups(['api', 'project'])
            ->label('scope', 'users.write')
            ->label('event', 'projects.[projectId].passwords.delete')
            ->label('audits.event', 'project.passwords.delete')
            ->label('audits.resource', 'project/{project.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'passwords',
                name: 'deletePasswords',
                description: <<<EOT
                Delete all passwords in the project, forcing every user to reset their password. Any password set before this request stops working for email and password sign-in immediately: signing in returns a `user_password_reset_required` error until the user sets a new password through password recovery, or until a new password is set for them with the Users API. Existing sessions are not affected; call the delete sessions endpoint to sign users out as well. Users without a password and API keys are not affected by this action.
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
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Event $queueForEvents,
        Authorization $authorization,
    ): void {
        // Passwords are not touched: email/password sign-in compares each user's
        // passwordUpdate against this cutoff, so the request stays O(1)
        $invalidatedAt = DateTime::formatTz(DateTime::now());

        $auths = $project->getAttribute('auths', []);
        $auths['passwordsInvalidatedAt'] = $invalidatedAt;

        $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('projectId', $project->getId());

        $response->noContent();
    }
}
