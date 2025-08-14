<?php

namespace Appwrite\Platform\Modules\Databases\Http\Transactions;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Range;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createTransaction';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/transactions')
            ->desc('Create transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'transactions.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'createTransaction',
                description: '/docs/references/databases/create-transaction.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('ttl', APP_DATABASE_TXN_TTL_DEFAULT, new Range(min: APP_DATABASE_TXN_TTL_MIN, max: APP_DATABASE_TXN_TTL_MAX), 'Seconds before the transaction expires.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(int $ttl, UtopiaResponse $response, Database $dbForProject): void
    {
        $transaction = $dbForProject->createDocument('transactions', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'operations' => 0,
            'expiresAt' => DateTime::addSeconds(new \DateTime(), $ttl),
        ]));

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }
}