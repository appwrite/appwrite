<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages\Sms;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createSMS';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/messages/sms')
            ->desc('Create SMS')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'message.create')
            ->label('audits.resource', 'message/{response.$id}')
            ->label('event', 'messages.[messageId].create')
            ->label('scope', 'messages.write')
            ->label('resourceType', RESOURCE_TYPE_MESSAGES)
            ->label('sdk', [
                new Method(
                    namespace: 'messaging',
                    group: 'messages',
                    name: 'createSms',
                    description: '/docs/references/messaging/create-sms.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_MESSAGE,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'messaging.createSMS',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'messaging',
                    group: 'messages',
                    name: 'createSMS',
                    description: '/docs/references/messaging/create-sms.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_MESSAGE,
                        )
                    ]
                )
            ])
            ->param('messageId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('content', '', new Text(64230), 'SMS Content.')
            ->param('topics', [], fn (Database $dbForProject) => new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'List of Topic IDs.', true, ['dbForProject'])
            ->param('users', [], fn (Database $dbForProject) => new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'List of User IDs.', true, ['dbForProject'])
            ->param('targets', [], fn (Database $dbForProject) => new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'List of Targets IDs.', true, ['dbForProject'])
            ->param('draft', false, new Boolean(), 'Is message a draft', true)
            ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('publisherForMessaging')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $messageId, string $content, ?array $topics, ?array $users, ?array $targets, bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, MessagingPublisher $publisherForMessaging, Response $response)
    {
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
                Query::equal('providerType', [MESSAGE_TYPE_SMS]),
                Query::limit(\count($targets)),
            ]);

            if (\count($foundTargets) !== \count($targets)) {
                throw new Exception(Exception::MESSAGE_TARGET_NOT_SMS);
            }

            foreach ($foundTargets as $target) {
                if ($target->isEmpty()) {
                    throw new Exception(Exception::USER_TARGET_NOT_FOUND);
                }
            }
        }

        $message = new Document([
            '$id' => $messageId,
            'providerType' => MESSAGE_TYPE_SMS,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'data' => [
                'content' => $content,
            ],
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
