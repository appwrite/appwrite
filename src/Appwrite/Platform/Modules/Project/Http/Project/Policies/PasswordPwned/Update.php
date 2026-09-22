<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPwned;

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
        return 'updateProjectPasswordPwnedPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-pwned')
            ->desc('Update password pwned policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordPwnedPolicy',
                description: <<<EOT
                Updating this policy allows you to control if passwords are checked against the Have I Been Pwned breach database. When enabled, every password a user signs up, signs in or resets with is checked and the result is recorded on the user as `passwordPwned`. On its own the policy only records. Enable `users` to reject a breached password when a user signs up or sets a new password, and `sessions` to refuse a sign-in with a breached password until it is reset. Only the first five characters of the password hash are ever shared with the service. Options left out keep their current value.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('enabled', null, new Boolean(), 'Toggle password pwned policy. Set to true to check passwords against known data breaches and record the result on the user, or false to never check. Default is true. On its own this only records; use `users` and `sessions` to block. When changing this policy, existing passwords remain valid.', optional: true)
            ->param('sessions', null, new Boolean(), 'Whether a sign-in with a breached password is refused until the password is reset. Default is false, which allows the sign-in and only records the result.', optional: true)
            ->param('users', null, new Boolean(), 'Whether a breached password is rejected when a user signs up or sets a new password. Default is false, which allows the password and only records the result.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?bool $enabled,
        ?bool $sessions,
        ?bool $users,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['passwordPwned'] = \array_merge([
            'enabled' => true,
            'sessions' => false,
            'users' => false,
        ], $auths['passwordPwned'] ?? []);

        if ($enabled !== null) {
            $auths['passwordPwned']['enabled'] = $enabled;
        }
        if ($sessions !== null) {
            $auths['passwordPwned']['sessions'] = $sessions;
        }
        if ($users !== null) {
            $auths['passwordPwned']['users'] = $users;
        }

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-pwned');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
