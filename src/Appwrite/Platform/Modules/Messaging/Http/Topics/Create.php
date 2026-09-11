<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
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
use Utopia\Database\Validator\Roles;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createTopic';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/topics')
            ->desc('Create topic')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'topic.create')
            ->label('audits.resource', 'topic/{response.$id}')
            ->label('event', 'topics.[topicId].create')
            ->label('scope', 'topics.write')
            ->label('resourceType', RESOURCE_TYPE_TOPICS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'topics',
                name: 'createTopic',
                description: '/docs/references/messaging/create-topic.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOPIC,
                    )
                ]
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID. Choose a custom Topic ID or a new Topic ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Topic Name.')
            ->param('subscribe', [Role::users()], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with subscribe permission. By default all users are granted with any subscribe permission. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
            ->param('qos', null, new Nullable(new Range(0, 1)), 'QoS for MQTT delivery on this topic (0 or 1). Null lets the subscriber choose.', true)
            ->param('expiry', null, new Nullable(new Range(0, 604800)), 'Message retention in seconds for offline delivery. Max 7 days (604800).', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, string $name, array $subscribe, ?int $qos, ?int $expiry, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $topicId = $topicId == 'unique()' ? ID::unique() : $topicId;

        $topic = new Document([
            '$id' => $topicId,
            'name' => $name,
            'subscribe' => $subscribe,
            'qos' => $qos,
            'expiry' => $expiry,
        ]);

        try {
            $topic = $dbForProject->createDocument('topics', $topic);
        } catch (DuplicateException) {
            throw new Exception(Exception::TOPIC_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($topic, Response::MODEL_TOPIC);
    }
}
