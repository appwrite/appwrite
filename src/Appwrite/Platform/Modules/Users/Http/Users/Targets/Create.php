<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Targets;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createUserTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/users/:userId/targets')
            ->desc('Create user target')
            ->groups(['api', 'users'])
            ->label('audits.event', 'target.create')
            ->label('audits.resource', 'target/response.$id')
            ->label('event', 'users.[userId].targets.[targetId].create')
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'targets',
                name: 'createTarget',
                description: '/docs/references/users/create-target.md',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TARGET,
                    )
                ]
            ))
            ->param('targetId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Target ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('providerType', '', new WhiteList([MESSAGE_TYPE_EMAIL, MESSAGE_TYPE_SMS, MESSAGE_TYPE_PUSH]), 'The target provider type. Can be one of the following: `email`, `sms` or `push`.', enum: new Enum(name: 'MessagingProviderType'))
            ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
            ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true, ['dbForProject'])
            ->param('name', '', new Text(128), 'Target name. Max length: 128 chars. For example: My Awesome App Galaxy S23.', true)
            ->inject('queueForEvents')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $targetId, string $userId, string $providerType, string $identifier, string $providerId, string $name, Event $queueForEvents, Response $response, Database $dbForProject): void
    {
        $targetId = $targetId == 'unique()' ? ID::unique() : $targetId;

        $provider = $dbForProject->getDocument('providers', $providerId);

        switch ($providerType) {
            case 'email':
                $validator = new EmailValidator();
                if (!$validator->isValid($identifier)) {
                    throw new Exception(Exception::GENERAL_INVALID_EMAIL);
                }
                break;
            case MESSAGE_TYPE_SMS:
                $validator = new Phone();
                if (!$validator->isValid($identifier)) {
                    throw new Exception(Exception::GENERAL_INVALID_PHONE);
                }
                break;
            case MESSAGE_TYPE_PUSH:
                break;
            default:
                throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $targetId);

        if (!$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        try {
            $target = $dbForProject->createDocument('targets', new Document([
                '$id' => $targetId,
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'providerId' => empty($provider->getId()) ? null : $provider->getId(),
                'providerInternalId' => $provider->isEmpty() ? null : $provider->getSequence(),
                'providerType' =>  $providerType,
                'userId' => $userId,
                'userInternalId' => $user->getSequence(),
                'identifier' => $identifier,
                'name' => ($name !== '') ? $name : null,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($target, Response::MODEL_TARGET);
    }
}
