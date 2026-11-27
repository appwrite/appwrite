<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Google;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Connection\Status;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Stores;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Account;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    private const array REQUIRED = ['client_email', 'private_key'];

    public static function getName(): string
    {
        return 'updateGoogleConnection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/connections/google/:connectionId')
            ->desc('Update Google connection')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionConnections.[connectionId].update')
            ->label('audits.event', 'distribution.connection.update')
            ->label('audits.resource', 'distributionConnection/{request.connectionId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'connections',
                name: 'updateGoogleConnection',
                description: <<<EOT
                Rename a Google Play connection or replace its service account. A new service account is checked against Google before it is saved; when the check fails the connection keeps its current service account.
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
            ->param('name', null, new Nullable(new Text(128)), 'Connection name. Max length: 128 chars.', true)
            ->param('serviceAccount', null, new Nullable(new JSON()), 'Contents of the service account JSON key file.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('distributionStores')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        ?string $name,
        #[\SensitiveParameter]
        array|string|null $serviceAccount,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Stores $distributionStores,
    ): void {
        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty() || $connection->getAttribute('provider') !== Provider::Google->value) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        if ($name === null && $serviceAccount === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Provide at least one of name or serviceAccount.');
        }

        $updates = [];

        if ($name !== null) {
            $updates['name'] = $name;
            $updates['search'] = \implode(' ', [$connection->getId(), $name, Provider::Google->value]);
        }

        if ($serviceAccount !== null) {
            $credentials = $this->credentials($serviceAccount);

            $account = $distributionStores->verify($distributionStores->adapter(Provider::Google, $credentials));

            $updates['credentials'] = $credentials;
            $updates['account'] = $this->account($account, $credentials['projectId']);
            $updates['status'] = Status::Active->value;
            $updates['error'] = '';
            $updates['checkedAt'] = DateTime::now();
        }

        $connection = $dbForProject->updateDocument('distributionConnections', $connection->getId(), new Document($updates));

        $queueForEvents->setParam('connectionId', $connection->getId());

        $response->dynamic($connection, Response::MODEL_DISTRIBUTION_CONNECTION);
    }

    /**
     * @param array<array-key, mixed>|string $serviceAccount
     * @return array{clientEmail: string, privateKey: string, projectId: mixed}
     */
    private function credentials(#[\SensitiveParameter] array|string $serviceAccount): array
    {
        if (\is_string($serviceAccount)) {
            $serviceAccount = \json_decode($serviceAccount, true);
        }

        if (!\is_array($serviceAccount)) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'The service account must be the JSON key file of a Google Cloud service account.');
        }

        foreach (self::REQUIRED as $field) {
            if (!\is_string($serviceAccount[$field] ?? null)) {
                throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, "The service account is missing {$field}.");
            }
        }

        return [
            'clientEmail' => $serviceAccount['client_email'],
            'privateKey' => $serviceAccount['private_key'],
            'projectId' => $serviceAccount['project_id'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function account(Account $account, mixed $projectId): array
    {
        $details = ['email' => $account->id];

        if (\is_string($projectId)) {
            $details['projectId'] = $projectId;
        }

        return $details;
    }
}
