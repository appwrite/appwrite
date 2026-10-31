<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Email;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Email;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateEmail';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/email')
            ->desc('Update email')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.email')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updateEmail',
                description: '/docs/references/account/update-email.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('hooks')
            ->inject('plan')
            ->inject('proofForPassword')
            ->inject('authorization')
            ->inject('passwordsDictionary')
            ->inject('pwnedPasswords')
            ->callback($this->action(...));
    }

    public function action(
        string $email,
        string $password,
        ?\DateTime $requestTimestamp,
        Response $response,
        User $user,
        Database $dbForProject,
        Event $queueForEvents,
        Document $project,
        Hooks $hooks,
        array $plan,
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
                $personalDataValidator = new PersonalData($user->getId(), $email, $user->getAttribute('name'), $user->getAttribute('phone'), denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
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

        $email = \strtolower($email);

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
            Query::notEqual('userInternalId', $user->getSequence()),
        ]);
        if (!$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST);
            /** Return a generic bad request to prevent exposing existing accounts */
        }

        $emailMetadata = [
            'emailCanonical' => null,
            'emailIsCanonical' => null,
            'emailIsCorporate' => null,
            'emailIsDisposable' => null,
            'emailIsFree' => null,
        ];

        try {
            $parsedEmail = new Email($email);
            $canonical = $parsedEmail->getCanonical();
            $emailMetadata = [
                'emailCanonical' => $canonical,
                'emailIsCanonical' => $parsedEmail->get() === $canonical,
                'emailIsCorporate' => $parsedEmail->isCorporate(),
                'emailIsDisposable' => $parsedEmail->isDisposable(),
                'emailIsFree' => $parsedEmail->isFree(),
            ];
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_INVALID_EMAIL);
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && $emailMetadata['emailIsDisposable']) {
            throw new Exception(Exception::USER_EMAIL_DISPOSABLE);
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && $emailMetadata['emailIsCanonical'] === false) {
            throw new Exception(Exception::USER_EMAIL_NOT_CANONICAL);
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && $emailMetadata['emailIsFree']) {
            throw new Exception(Exception::USER_EMAIL_FREE);
        }

        if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !$emailMetadata['emailIsCorporate']) {
            throw new Exception(Exception::USER_EMAIL_NOT_CORPORATE);
        }

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false) // After this user needs to confirm mail again
            ->setAttribute('emailCanonical', $emailMetadata['emailCanonical'])
            ->setAttribute('emailIsCanonical', $emailMetadata['emailIsCanonical'])
            ->setAttribute('emailIsCorporate', $emailMetadata['emailIsCorporate'])
            ->setAttribute('emailIsDisposable', $emailMetadata['emailIsDisposable'])
            ->setAttribute('emailIsFree', $emailMetadata['emailIsFree'])
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

        $target = $authorization->skip(fn () => $dbForProject->findOne('targets', [
            Query::equal('identifier', [$email]),
        ]));

        if (!$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        try {
            $user = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $user, $email) {
                $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));
                if ($current->isEmpty()) {
                    throw new Exception(Exception::USER_NOT_FOUND);
                }

                $lockedEmail = $current->getAttribute('email');

                $user = $dbForProject->updateDocument('users', $user->getId(), $user);
                $oldTarget = \array_find($user->getAttribute('targets', []), static fn (Document $target): bool => $target->getAttribute('identifier') === $lockedEmail);

                if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $email)));
                }

                $sequence = $user->getSequence();
                if ($lockedEmail !== $email && $sequence !== '' && $sequence !== null) {
                    $authorization->skip(fn () => $dbForProject->deleteDocuments('tokens', [
                        Query::equal('userInternalId', [$sequence]),
                        Query::equal('type', [
                            TOKEN_TYPE_RECOVERY,
                            TOKEN_TYPE_RECOVERY_OTP,
                            TOKEN_TYPE_VERIFICATION,
                            TOKEN_TYPE_VERIFICATION_OTP,
                            TOKEN_TYPE_MAGIC_URL,
                            TOKEN_TYPE_EMAIL,
                        ]),
                    ]));
                    $authorization->skip(fn () => $dbForProject->deleteDocuments('challenges', [
                        Query::equal('userInternalId', [$sequence]),
                        Query::equal('type', [Type::EMAIL]),
                    ]));
                    // A pending invite was sent to the old address, so it must not prove the new one
                    $authorization->skip(fn () => $dbForProject->updateDocuments('memberships', new Document(['secret' => '']), [
                        Query::equal('userInternalId', [$sequence]),
                        Query::equal('confirm', [false]),
                    ]));
                }

                return $user;
            });
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST);
            /** Return a generic bad request to prevent exposing existing accounts */
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
