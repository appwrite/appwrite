<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'delete';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/messaging/messages/:messageId')
            ->desc('Delete message')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'message.delete')
            ->label('audits.resource', 'message/{request.messageId}')
            ->label('event', 'messages.[messageId].delete')
            ->label('scope', 'messages.write')
            ->label('resourceType', RESOURCE_TYPE_MESSAGES)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'messages',
                name: 'delete',
                description: '/docs/references/messaging/delete-message.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('messageId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Message ID.', false, ['dbForProject'])
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $messageId, Database $dbForProject, Database $dbForPlatform, Event $queueForEvents, Response $response)
    {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        // Processing stays deletable: a worker that dies mid-send leaves the message there for good.
        switch ($message->getAttribute('status')) {
            case MessageStatus::SCHEDULED:
                $scheduleId = $message->getAttribute('scheduleId');
                $scheduledAt = $message->getAttribute('scheduledAt');

                $now = DateTime::now();
                $scheduledDate = DateTime::formatTz($scheduledAt);

                if ($now > $scheduledDate) {
                    throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
                }

                if (!empty($scheduleId)) {
                    try {
                        $dbForPlatform->deleteDocument('schedules', $scheduleId);
                    } catch (\Throwable) {
                        // Ignore
                    }
                }
                break;
            default:
                break;
        }

        $dbForProject->deleteDocument('messages', $message->getId());

        $queueForEvents
            ->setParam('messageId', $message->getId())
            ->setPayload($response->output($message, Response::MODEL_MESSAGE));

        $response->noContent();
    }
}
