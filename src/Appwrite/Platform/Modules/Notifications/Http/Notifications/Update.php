<?php

namespace Appwrite\Platform\Modules\Notifications\Http\Notifications;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateNotification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/notifications/:notificationId')
            ->desc('Update notification')
            ->groups(['api', 'notifications'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'notifications',
                group: null,
                name: 'update',
                description: '/docs/references/notifications/update-notification.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NOTIFICATION,
                    )
                ]
            ))
            ->param('notificationId', '', new UID(), 'Notification ID.')
            ->param('read', null, new Boolean(), 'Notification read status.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $notificationId,
        bool $read,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        Document $user,
    ): void {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $notification = $dbForPlatform->getDocument('notifications', $notificationId);

        if ($notification->isEmpty()) {
            $exists = $authorization->skip(fn () => !$dbForPlatform->getDocument('notifications', $notificationId)->isEmpty());
            if ($exists) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $updated = $dbForPlatform->updateDocument('notifications', $notificationId, new Document([
            'read' => $read,
        ]));

        $response->dynamic($updated, Response::MODEL_NOTIFICATION);
    }
}
