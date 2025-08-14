<?php

namespace Appwrite\Platform\Modules\Databases\Http\Transactions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getTransaction';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/transactions/:transactionId')
            ->desc('Get transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'transactions.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'getTransaction',
                description: '/docs/references/databases/get-transaction.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('transactionId', '', new UID(), 'Transaction ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $transactionId, UtopiaResponse $response, Database $dbForProject): void
    {
        $transaction = $dbForProject->getDocument('transactions', $transactionId);

        if ($transaction->isEmpty()) {
            throw new Exception(Exception::TRANSACTION_NOT_FOUND);
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }
}
