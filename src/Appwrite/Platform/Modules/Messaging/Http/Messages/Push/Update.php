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
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
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

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updatePush';
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
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/messages/push/:messageId')
            ->desc('Update push notification')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'message.update')
            ->label('audits.resource', 'message/{response.$id}')
            ->label('event', 'messages.[messageId].update')
            ->label('scope', 'messages.write')
            ->label('resourceType', RESOURCE_TYPE_MESSAGES)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'messages',
                name: 'updatePush',
                description: '/docs/references/messaging/update-push.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MESSAGE,
                    )
                ]
            ))
            ->param('messageId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Message ID.', false, ['dbForProject'])
            ->param('topics', null, fn (Database $dbForProject) => new Nullable(new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength()))), 'List of Topic IDs.', true, ['dbForProject'])
            ->param('users', null, fn (Database $dbForProject) => new Nullable(new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength()))), 'List of User IDs.', true, ['dbForProject'])
            ->param('targets', null, fn (Database $dbForProject) => new Nullable(new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength()))), 'List of Targets IDs.', true, ['dbForProject'])
            ->param('title', null, new Nullable(new Text(256)), 'Title for push notification.', true)
            ->param('body', null, new Nullable(new Text(64230)), 'Body for push notification.', true)
            ->param('data', null, new Nullable(new JSONObject()), 'Additional Data for push notification.', true)
            ->param('action', null, new Nullable(new Text(256)), 'Action for push notification.', true)
            ->param('image', null, new Nullable(new CompoundUID()), 'Image for push notification. Must be a compound bucket ID to file ID of a jpeg, png, or bmp image in Appwrite Storage. It should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
            ->param('icon', null, new Nullable(new Text(256)), 'Icon for push notification. Available only for Android and Web platforms.', true)
            ->param('sound', null, new Nullable(new Text(256)), 'Sound for push notification. Available only for Android and iOS platforms.', true)
            ->param('color', null, new Nullable(new Text(256)), 'Color for push notification. Available only for Android platforms.', true)
            ->param('tag', null, new Nullable(new Text(256)), 'Tag for push notification. Available only for Android platforms.', true)
            ->param('badge', null, new Nullable(new Integer()), 'Badge for push notification. Available only for iOS platforms.', true, example: '1')
            ->param('draft', null, new Nullable(new Boolean()), 'Is message a draft', true)
            ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
            ->param('contentAvailable', null, new Nullable(new Boolean()), 'If set to true, the notification will be delivered in the background. Available only for iOS Platform.', true)
            ->param('critical', null, new Nullable(new Boolean()), 'If set to true, the notification will be marked as critical. This requires the app to have the critical notification entitlement. Available only for iOS Platform.', true)
            ->param('priority', null, new Nullable(new WhiteList(['normal', 'high'])), 'Set the notification priority. "normal" will consider device battery state and may send notifications later. "high" will always attempt to immediately deliver the notification.', true, enum: new Enum(name: 'MessagePriority'))
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('publisherForMessaging')
            ->inject('response')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $title, ?string $body, null|array|\stdClass $data, ?string $action, ?string $image, ?string $icon, ?string $sound, ?string $color, ?string $tag, ?int $badge, ?bool $draft, ?string $scheduledAt, ?bool $contentAvailable, ?bool $critical, ?string $priority, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, MessagingPublisher $publisherForMessaging, Response $response, array $platform)
    {
        $data = $this->normalizeJsonObject($data);

        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if (!\is_null($draft) || !\is_null($scheduledAt)) {
            if ($draft) {
                $status = MessageStatus::DRAFT;
            } else {
                $status = \is_null($scheduledAt)
                    ? MessageStatus::PROCESSING
                    : MessageStatus::SCHEDULED;
            }
        } else {
            $status = $message->getAttribute('status');
        }

        if (
            $status !== MessageStatus::DRAFT
            && \count($topics ?? $message->getAttribute('topics', [])) === 0
            && \count($users ?? $message->getAttribute('users', [])) === 0
            && \count($targets ?? $message->getAttribute('targets', [])) === 0
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $currentScheduledAt = $message->getAttribute('scheduledAt');

        switch ($message->getAttribute('status')) {
            case MessageStatus::PROCESSING:
                throw new Exception(Exception::MESSAGE_ALREADY_PROCESSING);
            case MessageStatus::SENT:
                throw new Exception(Exception::MESSAGE_ALREADY_SENT);
            case MessageStatus::FAILED:
                throw new Exception(Exception::MESSAGE_ALREADY_FAILED);
        }

        if (
            $status === MessageStatus::SCHEDULED
            && \is_null($scheduledAt)
            && \is_null($currentScheduledAt)
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        if (!\is_null($currentScheduledAt) && new \DateTime($currentScheduledAt) < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
        }

        if (\is_null($currentScheduledAt) && !\is_null($scheduledAt)) {
            $schedule = $dbForPlatform->createDocument('schedules', new Document([
                'region' => $project->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getSequence(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForPlatform->getDocument('schedules', $message->getAttribute('scheduleId'));
            $scheduledStatus = ($status ?? $message->getAttribute('status')) === MessageStatus::SCHEDULED;

            if ($schedule->isEmpty()) {
                throw new Exception(Exception::SCHEDULE_NOT_FOUND);
            }

            $schedule
                ->setAttribute('projectInternalId', $project->getSequence())
                ->setAttribute('resourceUpdatedAt', DateTime::now())
                ->setAttribute('active', $scheduledStatus);

            if (!\is_null($scheduledAt)) {
                $schedule->setAttribute('schedule', $scheduledAt);
            }

            $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule);
        }

        if (!\is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        if (!\is_null($topics)) {
            $message->setAttribute('topics', $topics);
        }

        if (!\is_null($users)) {
            $message->setAttribute('users', $users);
        }

        if (!\is_null($targets)) {
            $message->setAttribute('targets', $targets);
        }

        $pushData = $message->getAttribute('data');

        if (!\is_null($title)) {
            $pushData['title'] = $title;
        }

        if (!\is_null($body)) {
            $pushData['body'] = $body;
        }

        if (!\is_null($data)) {
            $pushData['data'] = $data;
        }

        if (!\is_null($action)) {
            $pushData['action'] = $action;
        }

        if (!\is_null($icon)) {
            $pushData['icon'] = $icon;
        }

        if (!\is_null($sound)) {
            $pushData['sound'] = $sound;
        }

        if (!\is_null($color)) {
            $pushData['color'] = $color;
        }

        if (!\is_null($tag)) {
            $pushData['tag'] = $tag;
        }

        if (!\is_null($badge)) {
            $pushData['badge'] = $badge;
        }

        if (!\is_null($contentAvailable)) {
            $pushData['contentAvailable'] = $contentAvailable;
        }

        if (!\is_null($critical)) {
            $pushData['critical'] = $critical;
        }

        if (!\is_null($priority)) {
            $pushData['priority'] = $priority;
        }

        if (!\is_null($image)) {
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

            $scheduleTime = $currentScheduledAt ?? $scheduledAt;
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

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $endpoint = "$protocol://{$platform['apiHostname']}/v1";

            $pushData['image'] = [
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'url' => "{$endpoint}/storage/buckets/{$bucket->getId()}/files/{$file->getId()}/push?project={$project->getId()}&jwt={$jwt}",
            ];
        }

        $message->setAttribute('data', $pushData);

        if (!\is_null($status)) {
            $message->setAttribute('status', $status);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === MessageStatus::PROCESSING) {
            $publisherForMessaging->enqueue(new MessagingMessage(
                type: MESSAGE_SEND_TYPE_EXTERNAL,
                project: $project,
                messageId: $message->getId(),
            ));
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    }
}
