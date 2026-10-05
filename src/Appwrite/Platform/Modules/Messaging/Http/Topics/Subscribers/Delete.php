<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics\Subscribers;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteSubscriber';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
            ->desc('Delete subscriber')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'subscriber.delete')
            ->label('audits.resource', 'subscriber/{request.$subscriberId}')
            ->label('event', 'topics.[topicId].subscribers.[subscriberId].delete')
            ->label('scope', 'subscribers.write')
            ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'subscribers',
                name: 'deleteSubscriber',
                description: '/docs/references/messaging/delete-subscriber.md',
                auth: [AuthType::JWT, AuthType::SESSION, AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID. The topic ID subscribed to.', false, ['dbForProject'])
            ->param('subscriberId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Subscriber ID.', false, ['dbForProject'])
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, string $subscriberId, Event $queueForEvents, Database $dbForProject, Authorization $authorization, Response $response)
    {
        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty() || $subscriber->getAttribute('topicId') !== $topicId) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId'));

        $dbForProject->deleteDocument('subscribers', $subscriberId);

        $totalAttribute = match ($target->getAttribute('providerType')) {
            MESSAGE_TYPE_EMAIL => 'emailTotal',
            MESSAGE_TYPE_SMS => 'smsTotal',
            MESSAGE_TYPE_PUSH => 'pushTotal',
            default => throw new Exception(Exception::TARGET_PROVIDER_INVALID_TYPE),
        };

        $authorization->skip(fn () => $dbForProject->skipFilters(
            fn () => $dbForProject->decreaseDocumentAttribute(
                'topics',
                $topicId,
                $totalAttribute,
                min: 0
            ),
            APP_TOPICS_SUBQUERIES
        ));

        $queueForEvents
            ->setParam('topicId', $topic->getId())
            ->setParam('subscriberId', $subscriber->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    }
}
