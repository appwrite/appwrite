<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPersonalData;

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
        return 'updateProjectPasswordPersonalDataPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-personal-data')
            ->httpAlias('/v1/projects/:projectId/auth/personal-data')
            ->desc('Update password personal data policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordPersonalDataPolicy',
                description: <<<EOT
                Updating this policy allows you to control which personal data fields are checked against passwords. When a field is enabled, the password must not contain that value. Each field can be toggled independently. Existing passwords remain valid when changing this policy.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('userId', null, new Boolean(), 'Set to true to block passwords containing the user ID, or false to allow it.', optional: true)
            ->param('userEmail', null, new Boolean(), 'Set to true to block passwords containing the user email (or email local part), or false to allow it.', optional: true)
            ->param('userName', null, new Boolean(), 'Set to true to block passwords containing the user name, or false to allow it.', optional: true)
            ->param('userPhone', null, new Boolean(), 'Set to true to block passwords containing the user phone number, or false to allow it.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?bool $userId,
        ?bool $userEmail,
        ?bool $userName,
        ?bool $userPhone,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);

        if ($userId !== null) {
            $auths['personalDataCheckUserId'] = $userId;
        }
        if ($userEmail !== null) {
            $auths['personalDataCheckUserEmail'] = $userEmail;
        }
        if ($userName !== null) {
            $auths['personalDataCheckUserName'] = $userName;
        }
        if ($userPhone !== null) {
            $auths['personalDataCheckUserPhone'] = $userPhone;
        }

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-personal-data');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
