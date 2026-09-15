<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages\Email;

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
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateEmail';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/messages/email/:messageId')
            ->desc('Update email')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'message.update')
            ->label('audits.resource', 'message/{response.$id}')
            ->label('event', 'messages.[messageId].update')
            ->label('scope', 'messages.write')
            ->label('resourceType', RESOURCE_TYPE_MESSAGES)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'messages',
                name: 'updateEmail',
                description: '/docs/references/messaging/update-email.md',
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
            ->param('subject', null, new Nullable(new Text(998)), 'Email Subject.', true)
            ->param('content', null, new Nullable(new Text(64230)), 'Email Content.', true)
            ->param('draft', null, new Nullable(new Boolean()), 'Is message a draft', true)
            ->param('html', null, new Nullable(new Boolean()), 'Is content of type HTML', true)
            ->param('cc', null, fn (Database $dbForProject) => new Nullable(new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength()))), 'Array of target IDs to be added as CC.', true, ['dbForProject'])
            ->param('bcc', null, fn (Database $dbForProject) => new Nullable(new ArrayList(new UID($dbForProject->getAdapter()->getMaxUIDLength()))), 'Array of target IDs to be added as BCC.', true, ['dbForProject'])
            ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
            ->param('attachments', null, new Nullable(new ArrayList(new CompoundUID())), 'Array of compound ID strings of bucket IDs and file IDs to be attached to the email. They should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('publisherForMessaging')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $subject, ?string $content, ?bool $draft, ?bool $html, ?array $cc, ?array $bcc, ?string $scheduledAt, ?array $attachments, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, MessagingPublisher $publisherForMessaging, Response $response)
    {
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

        $data = $message->getAttribute('data');

        if (!\is_null($subject)) {
            $data['subject'] = $subject;
        }

        if (!\is_null($content)) {
            $data['content'] = $content;
        }

        if (!is_null($attachments)) {
            foreach ($attachments as &$attachment) {
                [$bucketId, $fileId] = CompoundUID::parse($attachment);

                $bucket = $dbForProject->getDocument('buckets', $bucketId);

                if ($bucket->isEmpty()) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }

                $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);

                if ($file->isEmpty()) {
                    throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                }

                $attachment = [
                    'bucketId' => $bucketId,
                    'fileId' => $fileId,
                ];
            }
            $data['attachments'] = $attachments;
        }

        if (!\is_null($html)) {
            $data['html'] = $html;
        }

        if (!\is_null($cc)) {
            $data['cc'] = $cc;
        }

        if (!\is_null($bcc)) {
            $data['bcc'] = $bcc;
        }

        $message->setAttribute('data', $data);

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
