<?php

namespace Appwrite\Platform\Modules\Account\Http\Alerts;

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
        return 'updateAlert';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/alerts/:alertId')
            ->desc('Update alert')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'alerts',
                name: 'updateAlert',
                description: '/docs/references/account/update-alert.md',
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT,
                    )
                ]
            ))
            ->param('alertId', '', new UID(), 'Alert ID.')
            ->param('read', null, new Boolean(), 'Alert read status.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $alertId,
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

        $alert = $dbForPlatform->getDocument('alerts', $alertId);

        if ($alert->isEmpty()) {
            $exists = $authorization->skip(fn () => !$dbForPlatform->getDocument('alerts', $alertId)->isEmpty());
            if ($exists) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $updated = $dbForPlatform->updateDocument('alerts', $alertId, new Document([
            'read' => $read,
        ]));

        $response->dynamic($updated, Response::MODEL_ALERT);
    }
}
