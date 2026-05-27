<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends PlatformAction
{
    use HTTP;

    public static function getName()
    {
        return 'getPresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/presences/:presenceId')
            ->desc('Get presence')
            ->groups(['api', 'presences'])
            ->label('scope', 'presences.read')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'get',
                desc: 'Get presence',
                description: '/docs/references/presences/get.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PRESENCE,
                    ),
                ],
            ))
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $presenceId, Response $response, Database $dbForProject): void
    {
        $presence = $dbForProject->getDocument('presenceLogs', $presenceId);
        if ($presence->isEmpty()) {
            throw new Exception(Exception::PRESENCE_NOT_FOUND);
        }

        $presenceExpiresAt = $presence->getAttribute('expiresAt');

        if (!empty($presenceExpiresAt) && DateTime::formatTz($presenceExpiresAt) < DateTime::formatTz(DateTime::now())) {
            throw new Exception(Exception::PRESENCE_NOT_FOUND);
        }

        $response->dynamic($presence, Response::MODEL_PRESENCE);
    }
}
