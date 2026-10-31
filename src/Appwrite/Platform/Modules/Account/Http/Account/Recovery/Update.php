<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Recovery;

use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
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
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AllOf;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRecovery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/recovery')
            ->desc('Update password recovery (confirmation)')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].recovery.[tokenId].update')
            ->label('audits.event', 'recovery.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'recovery',
                name: 'updateRecovery',
                description: '/docs/references/account/update-recovery.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{param-userId}')
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
            ->param('secret', '', new Text(256), 'Valid reset token.')
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('hooks')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('authorization')
            ->inject('pwnedPasswords')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $secret,
        string $password,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Hooks $hooks,
        ProofsPassword $proofForPassword,
        ProofsToken $proofForToken,
        Authorization $authorization,
        PasswordPwned $pwnedPasswords,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
    ): void {
        /** @var User $profile */
        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_RECOVERY, $secret, $proofForToken);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($profile->getId(), $profile->getAttribute('email'), $profile->getAttribute('name'), $profile->getAttribute('phone'), denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $newPassword = $proofForPassword->hash($password);

        $hash = ProofsPassword::createHash($profile->getAttribute('hash'), $profile->getAttribute('hashOptions'));
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $profile->getAttribute('passwordHistory', []);

        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $hash);
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
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

        $sessions = $profile->getAttribute('sessions', []);

        $claimed = false;
        $recoveryDocument = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $verifiedToken, $profile, $project, $newPassword, $history, $passwordPwned, $proofForPassword, &$claimed) {
            $claimed = false; // A retried attempt starts over
            $document = $dbForProject->getDocument('tokens', $verifiedToken->getId());
            if (!$dbForProject->deleteDocument('tokens', $verifiedToken->getId())) {
                return;
            }

            // Recovery proves an unverified email, so whoever registered it keeps no other way in
            $locked = $authorization->skip(fn () => $dbForProject->getDocument('users', $profile->getId(), forUpdate: true));
            if (!$locked->getAttribute('emailVerification', false)) {
                $authorization->skip(fn () => User::revokeCredentials($dbForProject, $profile, $project));
                $claimed = true;
            }

            $dbForProject->updateDocument('users', $profile->getId(), new Document(
                [
                    'password' => $newPassword,
                    'passwordHistory' => $history,
                    'passwordPwned' => $passwordPwned,
                    'passwordUpdate' => DateTime::now(),
                    'hash' => $proofForPassword->getHash()->getName(),
                    'hashOptions' => $proofForPassword->getHash()->getOptions(),
                    'emailVerification' => true]
            ));

            return $document;
        });

        // Thrown outside the transaction, which would otherwise retry the reuse as a transient failure.
        if ($recoveryDocument === null) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $profile = $dbForProject->getDocument('users', $profile->getId());
        $profile->setAttribute('sessions', $sessions);
        $user->setAttributes($profile->getArrayCopy());

        $invalidate = $project->getAttribute('auths', default: [])['invalidateSessions'] ?? true;
        if ($invalidate) {
            User::invalidateAuthentication($dbForProject, $profile);
        }

        $dbForProject->purgeCachedDocument('users', $profile->getId());

        try {
            $mqtt->revoke($project->getId(), $profile->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        if ($claimed) {
            // Sent before the request's own event, which would otherwise reach the registrant with the claimant's data
            (clone $queueForRealtime)
                ->reset()
                ->setProject($project)
                ->setEvent('users.[userId].sessions.delete')
                ->setParam('userId', $profile->getId())
                ->trigger();
        }

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recoveryDocument->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($recoveryDocument, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response->dynamic($recoveryDocument, Response::MODEL_TOKEN);
    }
}
