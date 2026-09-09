<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteTopic';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/messaging/topics/:topicId')
            ->desc('Delete topic')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'topic.delete')
            ->label('audits.resource', 'topic/{request.$topicId}')
            ->label('event', 'topics.[topicId].delete')
            ->label('scope', 'topics.write')
            ->label('resourceType', RESOURCE_TYPE_TOPICS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'topics',
                name: 'deleteTopic',
                description: '/docs/references/messaging/delete-topic.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID.', false, ['dbForProject'])
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('publisherForDeletes')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, Event $queueForEvents, Database $dbForProject, DeletePublisher $publisherForDeletes, Response $response)
    {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $dbForProject->deleteDocument('topics', $topicId);

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_TOPIC,
            document: $topic,
        ));

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    }
}
