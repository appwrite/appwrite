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
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Distribution\Account;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    private const array REQUIRED = ['client_email', 'private_key'];

    public static function getName(): string
    {
        return 'createGoogleConnection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/connections/google')
            ->desc('Create Google connection')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionConnections.[connectionId].create')
            ->label('audits.event', 'distribution.connection.create')
            ->label('audits.resource', 'distributionConnection/{response.$id}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'connections',
                name: 'createGoogleConnection',
                description: <<<EOT
                Connect a Google Cloud service account with access to Google Play. The service account is checked against Google before the connection is saved, and its private key is stored encrypted and never returned.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_CONNECTION,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('connectionId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Connection ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Connection name. Max length: 128 chars.')
            ->param('serviceAccount', [], new JSON(), 'Contents of the service account JSON key file.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('distributionStores')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        string $name,
        #[\SensitiveParameter]
        array|string $serviceAccount,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Stores $distributionStores,
    ): void {
        $connectionId = $connectionId === 'unique()' ? Id::unique() : $connectionId;

        if (!$dbForProject->getDocument('distributionConnections', $connectionId)->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_ALREADY_EXISTS);
        }

        $credentials = $this->credentials($serviceAccount);

        $account = $distributionStores->verify($distributionStores->adapter(Provider::Google, $credentials));

        try {
            $connection = $dbForProject->createDocument('distributionConnections', new Document([
                '$id' => $connectionId,
                'provider' => Provider::Google->value,
                'name' => $name,
                'credentials' => $credentials,
                'account' => $this->account($account, $credentials['projectId']),
                'status' => Status::Active->value,
                'error' => '',
                'checkedAt' => DateTime::now(),
                'search' => \implode(' ', [$connectionId, $name, Provider::Google->value]),
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('connectionId', $connection->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($connection, Response::MODEL_DISTRIBUTION_CONNECTION);
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
