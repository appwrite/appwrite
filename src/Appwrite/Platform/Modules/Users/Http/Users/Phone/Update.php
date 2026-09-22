<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Phone;

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
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserPhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/phone')
            ->desc('Update phone')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.phone')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updatePhone',
                description: '/docs/references/users/update-user-phone.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('number', '', new Phone(allowEmpty: true), 'User phone number.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $number, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $oldPhone = $user->getAttribute('phone');

        // Store null instead of empty string so unique constraint allows multiple users without phone
        $phoneValue = $number !== '' ? $number : null;

        $user
            ->setAttribute('phone', $phoneValue)
            ->setAttribute('phoneVerification', false)
        ;

        if ($number !== '') {
            $target = $dbForProject->findOne('targets', [
                Query::equal('identifier', [$number]),
            ]);

            if (!$target->isEmpty()) {
                throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
            }
        }

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), new Document([
                'phone' => $phoneValue,
                'phoneVerification' => $user->getAttribute('phoneVerification'),
            ]));
            $oldTarget = $user->find('identifier', $oldPhone, 'targets');

            if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                if ($number !== '') {
                    $dbForProject->updateDocument('targets', $oldTarget->getId(), new Document(['identifier' => $number]));
                    $oldTarget->setAttribute('identifier', $number);
                } else {
                    $dbForProject->deleteDocument('targets', $oldTarget->getId());
                }
            } else {
                if ($number !== '') {
                    $target = $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'providerType' => 'sms',
                        'identifier' => $number,
                    ]));
                    $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
                }
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
