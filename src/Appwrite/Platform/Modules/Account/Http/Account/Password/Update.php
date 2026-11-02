<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Password;

use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AllOf;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePassword';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/password')
            ->desc('Update password')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.password')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updatePassword',
                description: '/docs/references/account/update-password.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
            ->param('oldPassword', '', new PasswordFormat(new Text(256, 0)), 'Current user password. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('hooks')
            ->inject('proofForPassword')
            ->inject('pwnedPasswords')
            ->inject('session')
            ->inject('mqtt')
            ->callback($this->action(...));
    }

    public function action(
        string $password,
        string $oldPassword,
        Response $response,
        User $user,
        Document $project,
        Database $dbForProject,
        Event $queueForEvents,
        Hooks $hooks,
        ProofsPassword $proofForPassword,
        PasswordPwned $pwnedPasswords,
        ?Document $current,
        Mqtt $mqtt,
    ): void {
        $userProofForPassword = ProofsPassword::createHash($user->getAttribute('hash'), $user->getAttribute('hashOptions'));
        // Check old password only if its an existing user.
        if (!empty($user->getAttribute('passwordUpdate')) && !$userProofForPassword->verify($oldPassword, $user->getAttribute('password'))) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $newPassword = $proofForPassword->hash($password);
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $hash = ProofsPassword::createHash($user->getAttribute('hash'), $user->getAttribute('hashOptions'));
        $history = $user->getAttribute('passwordHistory', []);

        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $hash);
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, -$historyLimit);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($user->getId(), $user->getAttribute('email'), $user->getAttribute('name'), $user->getAttribute('phone'), denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        // null when the policy did not look, false when it looked and found nothing
        $pwnedPolicy = $project->getAttribute('auths', [])['passwordPwned'] ?? [];
        $passwordPwned = ($pwnedPolicy['enabled'] ?? true)
            ? !$pwnedPasswords->isValid($password)
            : null;
        if ($passwordPwned && ($pwnedPolicy['users'] ?? false)) {
            throw new Exception(Exception::USER_PASSWORD_PWNED);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        $user
            ->setAttribute('password', $newPassword)
            ->setAttribute('passwordHistory', $history)
            ->setAttribute('passwordPwned', $passwordPwned)
            ->setAttribute('passwordUpdate', DateTime::now())
            ->setAttribute('hash', $proofForPassword->getHash()->getName())
            ->setAttribute('hashOptions', $proofForPassword->getHash()->getOptions());

        $invalidate = $project->getAttribute('auths', default: [])['invalidateSessions'] ?? true;
        if ($invalidate && $current !== null) {
            User::invalidateAuthentication($dbForProject, $user, $current->getId());
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        try {
            $mqtt->revoke($project->getId(), $user->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
