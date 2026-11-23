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
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Account;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateAppleConnection';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/connections/apple/:connectionId')
            ->desc('Update Apple connection')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionConnections.[connectionId].update')
            ->label('audits.event', 'distribution.connection.update')
            ->label('audits.resource', 'distributionConnection/{request.connectionId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'connections',
                name: 'updateAppleConnection',
                description: <<<EOT
                Rename an App Store Connect connection or replace its API key. New key details are checked against App Store Connect before they are saved; when the check fails the connection keeps its current key.
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
            ->param('keyId', null, new Nullable(new Text(64)), 'App Store Connect API key ID.', true)
            ->param('issuerId', null, new Nullable(new Text(128)), 'App Store Connect issuer ID of the team key.', true)
            ->param('privateKey', null, new Nullable(new Text(8192)), 'Contents of the .p8 private key file, in PEM format.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('distributionStores')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        ?string $name,
        ?string $keyId,
        ?string $issuerId,
        #[\SensitiveParameter]
        ?string $privateKey,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Stores $distributionStores,
    ): void {
        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty() || $connection->getAttribute('provider') !== Provider::Apple->value) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $changes = \array_filter([
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'privateKey' => $privateKey,
        ], static fn (?string $value): bool => $value !== null);

        if ($name === null && $changes === []) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Provide at least one of name, keyId, issuerId or privateKey.');
        }

        $updates = [];

        if ($name !== null) {
            $updates['name'] = $name;
            $updates['search'] = \implode(' ', [$connection->getId(), $name, Provider::Apple->value]);
        }

        if ($changes !== []) {
            $current = $connection->getAttribute('credentials', []);
            $credentials = \array_merge(\is_array($current) ? $current : [], $changes);

            $account = $distributionStores->verify($distributionStores->adapter(Provider::Apple, $credentials));

            $updates['credentials'] = $credentials;
            $updates['account'] = $this->account($account, $credentials['keyId'], $credentials['issuerId']);
            $updates['status'] = Status::Active->value;
            $updates['error'] = '';
            $updates['checkedAt'] = DateTime::now();
        }

        $connection = $dbForProject->updateDocument('distributionConnections', $connection->getId(), new Document($updates));

        $queueForEvents->setParam('connectionId', $connection->getId());

        $response->dynamic($connection, Response::MODEL_DISTRIBUTION_CONNECTION);
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
