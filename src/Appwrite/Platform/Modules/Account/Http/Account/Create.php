<?php

namespace Appwrite\Platform\Modules\Account\Http\Account;

use Appwrite\Auth\Validator\EmailWhitelist;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\SetType;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Email;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AllOf;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'create';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account')
            ->desc('Create account')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'email-password')
            ->label('audits.event', 'user.create')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'create',
                description: '/docs/references/account/create.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
            ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('hooks')
            ->inject('plan')
            ->inject('pwnedPasswords')
            ->inject('proofForPassword')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $email,
        string $password,
        ?string $name,
        Request $request,
        Response $response,
        Document $user,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
        Hooks $hooks,
        array $plan,
        PasswordPwned $pwnedPasswords,
        ProofsPassword $proofForPassword,
    ): void {
        $name ??= '';
        $email = \strtolower($email);
        if ('console' === $project->getId()) {
            $whitelistEmails = $project->getAttribute('authWhitelistEmails');
            $whitelistIPs = $project->getAttribute('authWhitelistIPs');

            if (!empty($whitelistEmails) && !(new EmailWhitelist($whitelistEmails))->isValid($email)) {
                throw new Exception(Exception::USER_EMAIL_NOT_WHITELISTED);
            }

            if (!empty($whitelistIPs) && !\in_array($request->getIP(), $whitelistIPs)) {
                throw new Exception(Exception::USER_IP_NOT_WHITELISTED);
            }
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                if ('console' === $project->getId()) {
                    throw new Exception(Exception::USER_CONSOLE_COUNT_EXCEEDED);
                }
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
        ]);
        if (!$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST);
            /** Return a generic bad request to prevent exposing existing accounts */
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($userId, $email, $name, null, denyList: $project->getAttribute('auths', [])['personalDataDenyList'] ?? []);
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

        $passwordHistory = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $hash = $proofForPassword->hash($password);
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

        try {
            $userId = $userId == 'unique()' ? Id::unique() : $userId;
            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => $hash,
                'passwordHistory' => $passwordHistory > 0 ? [$hash] : [],
                'passwordUpdate' => DateTime::now(),
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'mfa' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'authenticators' => null,
                'search' => implode(' ', [$userId, $email, $name]),
                'accessedAt' => DateTime::now(),
                'emailCanonical' => $emailMetadata['emailCanonical'],
                'emailIsCanonical' => $emailMetadata['emailIsCanonical'],
                'emailIsCorporate' => $emailMetadata['emailIsCorporate'],
                'emailIsDisposable' => $emailMetadata['emailIsDisposable'],
                'emailIsFree' => $emailMetadata['emailIsFree'],
                'passwordPwned' => $passwordPwned,
            ]);

            $user->removeAttribute('$sequence');
            $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
            try {
                $target = $authorization->skip(fn () => $dbForProject->createDocument('targets', new Document([
                    '$permissions' => [
                        Permission::read(Role::user($user->getId())),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'userId' => $user->getId(),
                    'userInternalId' => $user->getSequence(),
                    'providerType' => MESSAGE_TYPE_EMAIL,
                    'identifier' => $email,
                ])));
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$email]),
                ]);
                if (!$existingTarget->isEmpty()) {
                    $user->setAttribute('targets', $existingTarget, SetType::Append);
                }
            }

            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate) {
            throw new Exception(Exception::USER_ALREADY_EXISTS);
        }

        $authorization->removeRole(Role::guests()->toString());
        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
