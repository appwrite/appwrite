<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createMFAAuthenticator';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/mfa/authenticators/:type')
            ->desc('Create authenticator')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.mfa')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'createMfaAuthenticator',
                    description: '/docs/references/account/create-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_TYPE,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.createMFAAuthenticator',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'createMFAAuthenticator',
                    description: '/docs/references/account/create-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_TYPE,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator. Must be `' . Type::TOTP . '`')
            ->inject('response')
            ->inject('project')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $type,
        Response $response,
        Document $project,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $otp = (match ($type) {
            Type::TOTP => new TOTP(),
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unknown type.')
        });

        $otp->setLabel($user->getAttribute('email'));
        $otp->setIssuer($project->getAttribute('name'));

        $authenticator = TOTP::getAuthenticatorFromUser($user);

        if ($authenticator) {
            if ($authenticator->getAttribute('verified')) {
                throw new Exception(Exception::USER_AUTHENTICATOR_ALREADY_VERIFIED);
            }
            $dbForProject->deleteDocument('authenticators', $authenticator->getId());
        }

        $authenticator = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => Type::TOTP,
            'verified' => false,
            'data' => [
                'secret' => $otp->getSecret(),
            ],
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]
        ]);

        $model = new Document([
            'secret' => $otp->getSecret(),
            'uri' => $otp->getProvisioningUri()
        ]);

        $authenticator = $dbForProject->createDocument('authenticators', $authenticator);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($model, Response::MODEL_MFA_TYPE);
    }
}
