<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Apple;

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
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAppleConnection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/connections/apple')
            ->desc('Create Apple connection')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionConnections.[connectionId].create')
            ->label('audits.event', 'distribution.connection.create')
            ->label('audits.resource', 'distributionConnection/{response.$id}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'connections',
                name: 'createAppleConnection',
                description: <<<EOT
                Connect an App Store Connect team API key. The key is checked against App Store Connect before the connection is saved, and its private key is stored encrypted and never returned.
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
            ->param('keyId', '', new Text(64), 'App Store Connect API key ID.')
            ->param('issuerId', '', new Text(128), 'App Store Connect issuer ID of the team key.')
            ->param('privateKey', '', new Text(8192), 'Contents of the .p8 private key file, in PEM format.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('distributionStores')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        string $name,
        string $keyId,
        string $issuerId,
        #[\SensitiveParameter]
        string $privateKey,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Stores $distributionStores,
    ): void {
        $connectionId = $connectionId === 'unique()' ? Id::unique() : $connectionId;

        if (!$dbForProject->getDocument('distributionConnections', $connectionId)->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_ALREADY_EXISTS);
        }

        $credentials = [
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'privateKey' => $privateKey,
        ];

        $account = $distributionStores->verify($distributionStores->adapter(Provider::Apple, $credentials));

        try {
            $connection = $dbForProject->createDocument('distributionConnections', new Document([
                '$id' => $connectionId,
                'provider' => Provider::Apple->value,
                'name' => $name,
                'credentials' => $credentials,
                'account' => $this->account($account, $keyId, $issuerId),
                'status' => Status::Active->value,
                'error' => '',
                'checkedAt' => DateTime::now(),
                'search' => \implode(' ', [$connectionId, $name, Provider::Apple->value]),
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
     * @return array<string, string>
     */
    private function account(Account $account, string $keyId, string $issuerId): array
    {
        $details = [
            'issuerId' => $issuerId,
            'keyId' => \substr($keyId, -4),
        ];

        if ($account->team !== null) {
            $details['team'] = $account->team;
        }

        return $details;
    }
}
