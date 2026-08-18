<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Targets;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/targets/:targetId')
            ->desc('Update user target')
            ->groups(['api', 'users'])
            ->label('audits.event', 'target.update')
            ->label('audits.resource', 'target/{response.$id}')
            ->label('event', 'users.[userId].targets.[targetId].update')
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'targets',
                name: 'updateTarget',
                description: '/docs/references/users/update-target.md',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TARGET,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Target ID.', false, ['dbForProject'])
            ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)', true)
            ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true, ['dbForProject'])
            ->param('name', '', new Text(128), 'Target name. Max length: 128 chars. For example: My Awesome App Galaxy S23.', true)
            ->inject('queueForEvents')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $targetId, string $identifier, string $providerId, string $name, Event $queueForEvents, Response $response, Database $dbForProject): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $targetId);

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getId() !== $target->getAttribute('userId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($identifier) {
            $providerType = $target->getAttribute('providerType');

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

            $target
                ->setAttribute('identifier', $identifier)
                ->setAttribute('expired', false);
        }

        if ($providerId) {
            $provider = $dbForProject->getDocument('providers', $providerId);

            if ($provider->isEmpty()) {
                throw new Exception(Exception::PROVIDER_NOT_FOUND);
            }

            if ($provider->getAttribute('type') !== $target->getAttribute('providerType')) {
                throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
            }

            $target
                ->setAttribute('providerId', $provider->getId())
                ->setAttribute('providerInternalId', $provider->getSequence());
        }

        if ($name) {
            $target->setAttribute('name', $name);
        }

        $target = $dbForProject->updateDocument('targets', $target->getId(), new Document([
            'identifier' => $target->getAttribute('identifier'),
            'expired' => $target->getAttribute('expired'),
            'providerId' => $target->getAttribute('providerId'),
            'providerInternalId' => $target->getAttribute('providerInternalId'),
            'name' => $target->getAttribute('name'),
        ]));
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->dynamic($target, Response::MODEL_TARGET);
    }
}
