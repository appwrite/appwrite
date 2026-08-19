<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Password;

use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator;
use Utopia\Validator\AllOf;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserPassword';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/password')
            ->desc('Update password')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.password')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updatePassword',
                description: '/docs/references/users/update-user-password.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? [], allowEmpty: true), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, allowEmpty: true)], Validator::TYPE_STRING)), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('hooks')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $password, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($userId, $user->getAttribute('email'), $user->getAttribute('name'), $user->getAttribute('phone'));
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        if (\strlen($password) === 0) {
            $user
                ->setAttribute('password', '')
                ->setAttribute('passwordUpdate', DateTime::now());

            $user = $dbForProject->updateDocument('users', $user->getId(), new Document([
                'password' => $user->getAttribute('password'),
                'passwordUpdate' => $user->getAttribute('passwordUpdate'),
            ]));
            $queueForEvents->setParam('userId', $user->getId());
            $response->dynamic($user, Response::MODEL_USER);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        // Create Argon2 hasher with default settings
        $hasher = new Argon2();

        $newPassword = $hasher->hash($password);

        $hash = ProofsPassword::createHash($user->getAttribute('hash'), $user->getAttribute('hashOptions'));
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $user->getAttribute('passwordHistory', []);

        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $hash);
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        $user
            ->setAttribute('password', $newPassword)
            ->setAttribute('passwordHistory', $history)
            ->setAttribute('passwordUpdate', DateTime::now())
            ->setAttribute('hash', $hasher->getName())
            ->setAttribute('hashOptions', $hasher->getOptions());

        $user = $dbForProject->updateDocument('users', $user->getId(), new Document([
            'password' => $user->getAttribute('password'),
            'passwordHistory' => $user->getAttribute('passwordHistory'),
            'passwordUpdate' => $user->getAttribute('passwordUpdate'),
            'hash' => $user->getAttribute('hash'),
            'hashOptions' => $user->getAttribute('hashOptions'),
        ]));

        $sessions = $user->getAttribute('sessions', []);
        $invalidate = $project->getAttribute('auths', default: [])['invalidateSessions'] ?? false;
        if ($invalidate) {
            foreach ($sessions as $session) {
                /** @var Document $session */
                $dbForProject->deleteDocument('sessions', $session->getId());
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
