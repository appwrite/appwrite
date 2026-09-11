<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Topics;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateTopic';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/topics/:topicId')
            ->desc('Update topic')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'topic.update')
            ->label('audits.resource', 'topic/{response.$id}')
            ->label('event', 'topics.[topicId].update')
            ->label('scope', 'topics.write')
            ->label('resourceType', RESOURCE_TYPE_TOPICS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'topics',
                name: 'updateTopic',
                description: '/docs/references/messaging/update-topic.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOPIC,
                    )
                ]
            ))
            ->param('topicId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Topic ID.', false, ['dbForProject'])
            ->param('name', null, new Nullable(new Text(128)), 'Topic Name.', true)
            ->param('subscribe', null, new Nullable(new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of role strings with subscribe permission. By default all users are granted with any subscribe permission. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
            ->param('qos', null, new Nullable(new Range(0, 1)), 'QoS for MQTT delivery on this topic (0 or 1). Null lets the subscriber choose.', true)
            ->param('expiry', null, new Nullable(new Range(0, 604800)), 'Message retention in seconds for offline delivery. Max 7 days (604800).', true)
            ->inject('request')
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $topicId, ?string $name, ?array $subscribe, ?int $qos, ?int $expiry, Request $request, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        if (!\is_null($name)) {
            $topic->setAttribute('name', $name);
        }

        if (!\is_null($subscribe)) {
            $topic->setAttribute('subscribe', $subscribe);
        }

        // qos and expiry are nullable settings: an explicit null resets them to the
        // documented default (subscriber-chosen QoS, no expiry). Since an omitted param
        // and an explicit null both arrive as null, honour the key's presence in the
        // request body rather than the value, so a reset isn't silently ignored.
        $params = $request->getParams();

        if (\array_key_exists('qos', $params)) {
            $topic->setAttribute('qos', $qos);
        }

        if (\array_key_exists('expiry', $params)) {
            $topic->setAttribute('expiry', $expiry);
        }

        $topic = $dbForProject->updateDocument('topics', $topicId, $topic);

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    }
}
