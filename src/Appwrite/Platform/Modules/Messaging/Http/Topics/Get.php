<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getTopic';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/messaging/topics/:topicId')
            ->desc('Get topic')
            ->groups(['api', 'messaging'])
            ->label('scope', 'topics.read')
            ->label('resourceType', RESOURCE_TYPE_TOPICS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'topics',
                name: 'getTopic',
                description: '/docs/references/messaging/get-topic.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOPIC,
                    )
                ]
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID.', false, ['dbForProject'])
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, Database $dbForProject, Response $response)
    {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    }
}
