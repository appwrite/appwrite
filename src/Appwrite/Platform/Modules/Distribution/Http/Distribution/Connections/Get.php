<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getConnection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/connections/:connectionId')
            ->desc('Get connection')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.read')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'connections',
                name: 'getConnection',
                description: <<<EOT
                Get a store connection by its unique ID. Credentials are never returned.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_CONNECTION,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('connectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Connection ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        Response $response,
        Database $dbForProject,
    ): void {
        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $response->dynamic($connection, Response::MODEL_DISTRIBUTION_CONNECTION);
    }
}
