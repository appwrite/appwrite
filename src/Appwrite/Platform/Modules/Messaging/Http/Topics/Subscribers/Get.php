<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getSubscriber';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
            ->desc('Get subscriber')
            ->groups(['api', 'messaging'])
            ->label('scope', 'subscribers.read')
            ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'subscribers',
                name: 'getSubscriber',
                description: '/docs/references/messaging/get-subscriber.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SUBSCRIBER,
                    )
                ]
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID. The topic ID subscribed to.', false, ['dbForProject'])
            ->param('subscriberId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Subscriber ID.', false, ['dbForProject'])
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, string $subscriberId, Database $dbForProject, Authorization $authorization, Response $response)
    {
        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty() || $subscriber->getAttribute('topicId') !== $topicId) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId')));
        $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

        $subscriber
            ->setAttribute('target', $target)
            ->setAttribute('userName', $user->getAttribute('name'));

        $response
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    }
}
