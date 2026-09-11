<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Messages;

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
        return 'getMessage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/messaging/messages/:messageId')
            ->desc('Get message')
            ->groups(['api', 'messaging'])
            ->label('scope', 'messages.read')
            ->label('resourceType', RESOURCE_TYPE_MESSAGES)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'messages',
                name: 'getMessage',
                description: '/docs/references/messaging/get-message.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MESSAGE,
                    )
                ]
            ))
            ->param('messageId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Message ID.', false, ['dbForProject'])
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $messageId, Database $dbForProject, Response $response)
    {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        $response->dynamic($message, Response::MODEL_MESSAGE);
    }
}
