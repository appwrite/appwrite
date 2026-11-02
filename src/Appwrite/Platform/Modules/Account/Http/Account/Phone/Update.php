<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Phone;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/phone')
            ->desc('Update phone')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.phone')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updatePhone',
                description: '/docs/references/account/update-phone.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
            ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('hooks')
            ->inject('proofForPassword')
            ->inject('authorization')
            ->inject('passwordsDictionary')
            ->inject('pwnedPasswords')
            ->callback($this->action(...));
    }

    public function action(
        string $phone,
        string $password,
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents,
        Document $project,
        Hooks $hooks,
        ProofsPassword $proofForPassword,
        Authorization $authorization,
        array $passwordsDictionary,
        PasswordPwned $pwnedPasswords,
    ): void {
        // passwordUpdate will be empty if the user has never set a password
        $passwordUpdate = $user->getAttribute('passwordUpdate');

        $userProofForPassword = ProofsPassword::createHash($user->getAttribute('hash'), $user->getAttribute('hashOptions'));

        if (
            !empty($passwordUpdate) &&
            !$userProofForPassword->verify($password, $user->getAttribute('password'))
        ) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $passwordPwned = null;
        if (empty($passwordUpdate)) {
            // First password for an anonymous account: apply the same policies as account creation
            $strength = new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []);
            if (!$strength->isValid($password)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `password` param: ' . $strength->getDescription());
            }

            $dictionary = new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? []);
            if (!$dictionary->isValid($password)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `password` param: ' . $dictionary->getDescription());
            }

            if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
                $personalDataValidator = new PersonalData($user->getId(), $user->getAttribute('email'), $user->getAttribute('name'), $phone, denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
                if (!$personalDataValidator->isValid($password)) {
                    throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
                }
            }

            $pwnedPolicy = $project->getAttribute('auths', [])['passwordPwned'] ?? [];
            $passwordPwned = ($pwnedPolicy['enabled'] ?? true)
                ? !$pwnedPasswords->isValid($password)
                : null;
            if ($passwordPwned && ($pwnedPolicy['users'] ?? false)) {
                throw new Exception(Exception::USER_PASSWORD_PWNED);
            }
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, false]);

        $target = $authorization->skip(fn () => $dbForProject->findOne('targets', [
            Query::equal('identifier', [$phone]),
        ]));

        if (!$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        $user
            ->setAttribute('phone', $phone)
            ->setAttribute('phoneVerification', false) // After this user needs to confirm phone number again
        ;

        if (empty($passwordUpdate)) {
            $newPassword = $proofForPassword->hash($password);
            $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
            $user
                ->setAttribute('password', $newPassword)
                ->setAttribute('passwordHistory', $historyLimit > 0 ? [$newPassword] : [])
                ->setAttribute('passwordPwned', $passwordPwned)
                ->setAttribute('hash', $proofForPassword->getHash()->getName())
                ->setAttribute('hashOptions', $proofForPassword->getHash()->getOptions())
                ->setAttribute('passwordUpdate', DateTime::now());
        }

        try {
            $user = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $user, $phone) {
                $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));
                if ($current->isEmpty()) {
                    throw new Exception(Exception::USER_NOT_FOUND);
                }

                $lockedPhone = $current->getAttribute('phone');

                $user = $dbForProject->updateDocument('users', $user->getId(), $user);
                $oldTarget = \array_find($user->getAttribute('targets', []), static fn (Document $target): bool => $target->getAttribute('identifier') === $lockedPhone);

                if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $phone)));
                }

                $sequence = $user->getSequence();
                if ($lockedPhone !== $phone && $sequence !== '' && $sequence !== null) {
                    $authorization->skip(function () use ($dbForProject, $sequence) {
                        $dbForProject->deleteDocuments('tokens', [
                            Query::equal('userInternalId', [$sequence]),
                            Query::equal('type', [TOKEN_TYPE_PHONE]),
                        ]);
                        $dbForProject->deleteDocuments('challenges', [
                            Query::equal('userInternalId', [$sequence]),
                            Query::equal('type', [Type::PHONE]),
                        ]);
                        // A pending invite was sent to the old number, so it must not prove the new one
                        $dbForProject->updateDocuments('memberships', new Document(['secret' => '']), [
                            Query::equal('userInternalId', [$sequence]),
                            Query::equal('confirm', [false]),
                        ]);
                    });
                }

                return $user;
            });
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
