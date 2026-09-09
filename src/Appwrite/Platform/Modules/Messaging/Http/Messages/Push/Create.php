<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages\Push;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CompoundUID;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON\ObjectValidator as JSONObject;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createPush';
    }

    /**
     * Convert a request JSON object to the associative shape messages expect while
     * retaining empty objects at any nested depth. Returns null untouched so the
     * caller can distinguish "not provided" from an explicit empty object.
     */
    private function normalizeJsonObject(null|array|\stdClass $data): ?array
    {
        if (\is_null($data)) {
            return null;
        }

        $normalizeValue = function (mixed $value) use (&$normalizeValue): mixed {
            if ($value instanceof \stdClass) {
                $properties = (array) $value;

                return $properties === []
                    ? $value
                    : \array_map($normalizeValue, $properties);
            }

            if (\is_array($value)) {
                return \array_map($normalizeValue, $value);
            }

            return $value;
        };

        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }

        return \array_map($normalizeValue, $data);
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/messages/push')
            ->desc('Create push notification')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'message.create')
            ->label('audits.resource', 'message/{response.$id}')
            ->label('event', 'messages.[messageId].create')
            ->label('scope', 'messages.write')
            ->label('resourceType', RESOURCE_TYPE_MESSAGES)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'messages',
                name: 'createPush',
                description: '/docs/references/messaging/create-push.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_MESSAGE,
                    )
                ]
            ))
            ->param('messageId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('title', '', new Text(256), 'Title for push notification.', true)
            ->param('body', '', new Text(64230), 'Body for push notification.', true)
            ->param('topics', [], fn (Database $dbForProject) => new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'List of Topic IDs.', true, ['dbForProject'])
            ->param('users', [], fn (Database $dbForProject) => new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'List of User IDs.', true, ['dbForProject'])
            ->param('targets', [], fn (Database $dbForProject) => new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'List of Targets IDs.', true, ['dbForProject'])
            ->param('data', null, new Nullable(new JSONObject()), 'Additional key-value pair data for push notification.', true)
            ->param('action', '', new Text(256), 'Action for push notification.', true)
            ->param('image', '', new CompoundUID(), 'Image for push notification. Must be a compound bucket ID to file ID of a jpeg, png, or bmp image in Appwrite Storage. It should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
            ->param('icon', '', new Text(256), 'Icon for push notification. Available only for Android and Web Platform.', true)
            ->param('sound', '', new Text(256), 'Sound for push notification. Available only for Android and iOS Platform.', true)
            ->param('color', '', new Text(256), 'Color for push notification. Available only for Android Platform.', true)
            ->param('tag', '', new Text(256), 'Tag for push notification. Available only for Android Platform.', true)
            ->param('badge', -1, new Integer(), 'Badge for push notification. Available only for iOS Platform.', true, example: '1')
            ->param('draft', false, new Boolean(), 'Is message a draft', true)
            ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
            ->param('contentAvailable', false, new Boolean(), 'If set to true, the notification will be delivered in the background. Available only for iOS Platform.', true)
            ->param('critical', false, new Boolean(), 'If set to true, the notification will be marked as critical. This requires the app to have the critical notification entitlement. Available only for iOS Platform.', true)
            ->param('priority', 'high', new WhiteList(['normal', 'high']), 'Set the notification priority. "normal" will consider device state and may not deliver notifications immediately. "high" will always attempt to immediately deliver the notification.', true, enum: new Enum(name: 'MessagePriority'))
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('publisherForMessaging')
            ->inject('response')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(string $messageId, string $title, string $body, ?array $topics, ?array $users, ?array $targets, null|array|\stdClass $data, string $action, string $image, string $icon, string $sound, string $color, string $tag, int $badge, bool $draft, ?string $scheduledAt, bool $contentAvailable, bool $critical, string $priority, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, MessagingPublisher $publisherForMessaging, Response $response, array $platform)
    {
        $data = $this->normalizeJsonObject($data);

        $messageId = $messageId == 'unique()'
            ? ID::unique()
            : $messageId;

        if ($draft) {
            $status = MessageStatus::DRAFT;
        } else {
            $status = \is_null($scheduledAt)
                ? MessageStatus::PROCESSING
                : MessageStatus::SCHEDULED;
        }

        if ($status !== MessageStatus::DRAFT && \count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        if (!empty($targets)) {
            $foundTargets = $dbForProject->find('targets', [
                Query::equal('$id', $targets),
                Query::equal('providerType', [MESSAGE_TYPE_PUSH]),
                Query::limit(\count($targets)),
            ]);

            if (\count($foundTargets) !== \count($targets)) {
                throw new Exception(Exception::MESSAGE_TARGET_NOT_PUSH);
            }

            foreach ($foundTargets as $target) {
                if ($target->isEmpty()) {
                    throw new Exception(Exception::USER_TARGET_NOT_FOUND);
                }
            }
        }

        if (!empty($image)) {
            [$bucketId, $fileId] = CompoundUID::parse($image);

            $bucket = $dbForProject->getDocument('buckets', $bucketId);
            if ($bucket->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
            if ($file->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!\in_array($file->getAttribute('mimeType'), ['image/png', 'image/jpeg'])) {
                throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
            }

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $endpoint = "$protocol://{$platform['apiHostname']}/v1";

            $scheduleTime = $scheduledAt;
            if (!\is_null($scheduleTime)) {
                $expiry = (new \DateTime($scheduleTime))->add(new \DateInterval('P15D'))->format('U');
            } else {
                $expiry = (new \DateTime())->add(new \DateInterval('P15D'))->format('U');
            }

            $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', \intval($expiry), 0);

            $jwt = $encoder->encode([
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'projectId' => $project->getId(),
            ]);

            $image = [
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'url' => "{$endpoint}/storage/buckets/{$bucket->getId()}/files/{$file->getId()}/push?project={$project->getId()}&jwt={$jwt}",
            ];
        }

        $pushData = [];

        if (!empty($title)) {
            $pushData['title'] = $title;
        }
        if (!empty($body)) {
            $pushData['body'] = $body;
        }
        if (!empty($data)) {
            $pushData['data'] = $data;
        }
        if (!empty($action)) {
            $pushData['action'] = $action;
        }
        if (!empty($image)) {
            $pushData['image'] = $image;
        }
        if (!empty($icon)) {
            $pushData['icon'] = $icon;
        }
        if (!empty($sound)) {
            $pushData['sound'] = $sound;
        }
        if (!empty($color)) {
            $pushData['color'] = $color;
        }
        if (!empty($tag)) {
            $pushData['tag'] = $tag;
        }
        if ($badge >= 0) {
            $pushData['badge'] = $badge;
        }
        if ($contentAvailable) {
            $pushData['contentAvailable'] = true;
        }
        if ($critical) {
            $pushData['critical'] = true;
        }
        if (!empty($priority)) {
            $pushData['priority'] = $priority;
        }

        $message = new Document([
            '$id' => $messageId,
            'providerType' => MESSAGE_TYPE_PUSH,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'scheduledAt' => $scheduledAt,
            'data' => $pushData,
            'status' => $status,
        ]);
        try {
            $message = $dbForProject->createDocument('messages', $message);
        } catch (DuplicateException) {
            throw new Exception(Exception::MESSAGE_ALREADY_EXISTS);
        }

        switch ($status) {
            case MessageStatus::PROCESSING:
                $publisherForMessaging->enqueue(new MessagingMessage(
                    type: MESSAGE_SEND_TYPE_EXTERNAL,
                    project: $project,
                    messageId: $message->getId(),
                ));
                break;
            case MessageStatus::SCHEDULED:
                $schedule = $dbForPlatform->createDocument('schedules', new Document([
                    'region' => $project->getAttribute('region'),
                    'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getSequence(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getSequence(),
                    'schedule' => $scheduledAt,
                    'active' => true,
                ]));

                $message->setAttribute('scheduleId', $schedule->getId());

                $dbForProject->updateDocument(
                    'messages',
                    $message->getId(),
                    $message
                );
                break;
            default:
                break;
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    }
}
