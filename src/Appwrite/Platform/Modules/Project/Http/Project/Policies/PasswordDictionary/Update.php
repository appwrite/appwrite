<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordDictionary;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasswordDictionaryPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-dictionary')
            ->httpAlias('/v1/projects/:projectId/auth/password-dictionary')
            ->desc('Update password dictionary policy')
            ->groups(['api', 'project'])
            ->label('scope', 'policies.write')
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordDictionaryPolicy',
                description: <<<EOT
                Updating this policy allows you to control if new passwords are checked against most common passwords dictionary. When enabled, and user changes their password, password must not be contained in the dictionary.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('enabled', null, new Boolean(), 'Toggle password dictionary policy. Set to true if you want password change to block passwords in the dictionary, or false to allow them. When changing this policy, existing passwords remain valid.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['passwordDictionary'] = $enabled;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-dictionary');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
