<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteConnection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/connections/:connectionId')
            ->desc('Delete connection')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionConnections.[connectionId].delete')
            ->label('audits.event', 'distribution.connection.delete')
            ->label('audits.resource', 'distributionConnection/{request.connectionId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'connections',
                name: 'deleteConnection',
                description: <<<EOT
                Delete a store connection. A connection used by a target or a signing identity cannot be deleted. Nothing is revoked at the store; revoke the key or service account there if it is no longer needed.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('connectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Connection ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
    ): void {
        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $references = [Query::equal('connectionInternalId', [$connection->getSequence()])];

        if (
            $dbForProject->count('distributionTargets', $references, 1) > 0
            || $dbForProject->count('distributionSigningIdentities', $references, 1) > 0
        ) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_IN_USE);
        }

        if (!$dbForProject->deleteDocument('distributionConnections', $connection->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove connection from DB');
        }

        $queueForEvents
            ->setParam('connectionId', $connection->getId())
            ->setPayload($response->output($connection, Response::MODEL_DISTRIBUTION_CONNECTION));

        $response->noContent();
    }
}
