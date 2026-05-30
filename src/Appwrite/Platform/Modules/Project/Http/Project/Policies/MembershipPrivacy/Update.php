<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\MembershipPrivacy;

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
        return 'updateProjectMembershipPrivacyPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/membership-privacy')
            ->httpAlias('/v1/projects/:projectId/auth/memberships-privacy')
            ->desc('Update membership privacy policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updateMembershipPrivacyPolicy',
                description: <<<EOT
                Updating this policy allows you to control if team members can see other members information. When enabled, all team members can see ID, name, email, phone number, and MFA status of other members..
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('userId', null, new Boolean(), 'Set to true if you want make user ID visible to all team members, or false to hide it.', optional: true)
            ->param('userEmail', null, new Boolean(), 'Set to true if you want make user email visible to all team members, or false to hide it.', optional: true)
            ->param('userPhone', null, new Boolean(), 'Set to true if you want make user phone number visible to all team members, or false to hide it.', optional: true)
            ->param('userName', null, new Boolean(), 'Set to true if you want make user name visible to all team members, or false to hide it.', optional: true)
            ->param('userMFA', null, new Boolean(), 'Set to true if you want make user MFA status visible to all team members, or false to hide it.', optional: true)
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
        ?bool $userPhone,
        ?bool $userName,
        ?bool $userMFA,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);

        if ($userId !== null) {
            $auths['membershipsUserId'] = $userId;
        }
        if ($userEmail !== null) {
            $auths['membershipsUserEmail'] = $userEmail;
        }
        if ($userPhone !== null) {
            $auths['membershipsUserPhone'] = $userPhone;
        }
        if ($userName !== null) {
            $auths['membershipsUserName'] = $userName;
        }
        if ($userMFA !== null) {
            $auths['membershipsMfa'] = $userMFA;
        }

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'membership-privacy');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
