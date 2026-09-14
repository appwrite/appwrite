<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Email;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Email;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserEmail';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/email')
            ->desc('Update email')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.email')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updateEmail',
                description: '/docs/references/users/update-user-email.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(allowEmpty: true), 'User email.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('plan')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $email, Response $response, Database $dbForProject, Document $project, array $plan, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $email = \strtolower($email);

        if (\strlen($email) !== 0) {
            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
                Query::notEqual('userInternalId', $user->getSequence()),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            $target = $dbForProject->findOne('targets', [
                Query::equal('identifier', [$email]),
            ]);

            if (!$target->isEmpty()) {
                throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
            }
        }

        $oldEmail = $user->getAttribute('email');

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
        }

        if ((($project->getId() === 'console') || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && ($emailMetadata['emailIsDisposable'] ?? false)) {
            throw new Exception(Exception::USER_EMAIL_DISPOSABLE);
        }

        if ((($project->getId() === 'console') || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && ($emailMetadata['emailIsCanonical'] ?? true) === false) {
            throw new Exception(Exception::USER_EMAIL_NOT_CANONICAL);
        }

        if ((($project->getId() === 'console') || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && ($emailMetadata['emailIsFree'] ?? false)) {
            throw new Exception(Exception::USER_EMAIL_FREE);
        }

        if ((($project->getId() === 'console') || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !($emailMetadata['emailIsCorporate'] ?? true)) {
            throw new Exception(Exception::USER_EMAIL_NOT_CORPORATE);
        }

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false)
            ->setAttribute('emailCanonical', $emailMetadata['emailCanonical'])
            ->setAttribute('emailIsCanonical', $emailMetadata['emailIsCanonical'])
            ->setAttribute('emailIsCorporate', $emailMetadata['emailIsCorporate'])
            ->setAttribute('emailIsDisposable', $emailMetadata['emailIsDisposable'])
            ->setAttribute('emailIsFree', $emailMetadata['emailIsFree'])
        ;

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), new Document([
                'email' => $user->getAttribute('email'),
                'emailVerification' => $user->getAttribute('emailVerification'),
                'emailCanonical' => $user->getAttribute('emailCanonical'),
                'emailIsCanonical' => $user->getAttribute('emailIsCanonical'),
                'emailIsCorporate' => $user->getAttribute('emailIsCorporate'),
                'emailIsDisposable' => $user->getAttribute('emailIsDisposable'),
                'emailIsFree' => $user->getAttribute('emailIsFree'),
            ]));
            $oldTarget = $user->find('identifier', $oldEmail, 'targets');

            if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                if (\strlen($email) !== 0) {
                    $dbForProject->updateDocument('targets', $oldTarget->getId(), new Document(['identifier' => $email]));
                    $oldTarget->setAttribute('identifier', $email);
                } else {
                    $dbForProject->deleteDocument('targets', $oldTarget->getId());
                }
            } else {
                if (\strlen($email) !== 0) {
                    $target = $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'providerType' => 'email',
                        'identifier' => $email,
                    ]));
                    $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
                }
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
