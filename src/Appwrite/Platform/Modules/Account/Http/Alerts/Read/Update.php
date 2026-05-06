<?php

namespace Appwrite\Platform\Modules\Account\Http\Alerts\Read;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateAlertRead';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/alerts/:alertId/read')
            ->desc('Mark alert read')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'alerts',
                name: 'updateAlertRead',
                description: '/docs/references/account/update-alert-read.md',
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT,
                    )
                ]
            ))
            ->param('alertId', '', new UID(), 'Alert ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $alertId,
        Response $response,
        Database $dbForPlatform,
        Document $user,
    ): void {
        $alert = $dbForPlatform->getDocument('alerts', $alertId);

        if ($alert->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        if ($alert->getAttribute('userId') !== $user->getId()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $updated = $dbForPlatform->updateDocument('alerts', $alertId, new Document([
            'read' => true,
        ]));

        $response->dynamic($updated, Response::MODEL_ALERT);
    }
}
