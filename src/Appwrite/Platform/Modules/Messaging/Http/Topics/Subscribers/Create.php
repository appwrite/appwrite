<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createSubscriber';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/topics/:topicId/subscribers')
            ->desc('Create subscriber')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'subscriber.create')
            ->label('audits.resource', 'subscriber/{response.$id}')
            ->label('event', 'topics.[topicId].subscribers.[subscriberId].create')
            ->label('scope', 'subscribers.write')
            ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'subscribers',
                name: 'createSubscriber',
                description: '/docs/references/messaging/create-subscriber.md',
                auth: [AuthType::JWT, AuthType::SESSION, AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SUBSCRIBER,
                    )
                ]
            ))
            ->param('subscriberId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Subscriber ID. Choose a custom Subscriber ID or a new Subscriber ID.', false, ['dbForProject'])
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID. The topic ID to subscribe to.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Target ID. The target ID to link to the specified Topic ID.', false, ['dbForProject'])
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $subscriberId, string $topicId, string $targetId, Event $queueForEvents, Database $dbForProject, Authorization $authorization, Response $response)
    {
        $subscriberId = $subscriberId == 'unique()' ? ID::unique() : $subscriberId;

        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }
        if (!$authorization->isValid(new Input('subscribe', $topic->getAttribute('subscribe')))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

        $subscriber = new Document([
            '$id' => $subscriberId,
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
            'topicId' => $topicId,
            'topicInternalId' => $topic->getSequence(),
            'targetId' => $targetId,
            'targetInternalId' => $target->getSequence(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'providerType' => $target->getAttribute('providerType'),
            'search' => implode(' ', [
                $subscriberId,
                $targetId,
                $user->getId(),
                $target->getAttribute('providerType'),
            ]),
        ]);

        try {
            $subscriber = $dbForProject->createDocument('subscribers', $subscriber);

            $totalAttribute = match ($target->getAttribute('providerType')) {
                MESSAGE_TYPE_EMAIL => 'emailTotal',
                MESSAGE_TYPE_SMS => 'smsTotal',
                MESSAGE_TYPE_PUSH => 'pushTotal',
                default => throw new Exception(Exception::TARGET_PROVIDER_INVALID_TYPE),
            };

            $authorization->skip(fn () => $dbForProject->skipFilters(
                fn () => $dbForProject->increaseDocumentAttribute(
                    'topics',
                    $topicId,
                    $totalAttribute,
                ),
                APP_TOPICS_SUBQUERIES
            ));
        } catch (DuplicateException) {
            throw new Exception(Exception::SUBSCRIBER_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('topicId', $topic->getId())
            ->setParam('subscriberId', $subscriber->getId());

        $subscriber
            ->setAttribute('target', $target)
            ->setAttribute('userName', $user->getAttribute('name'));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    }
}
