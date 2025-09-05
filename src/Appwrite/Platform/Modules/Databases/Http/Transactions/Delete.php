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
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteTransaction';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_NONE;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/transactions/:transactionId')
            ->desc('Delete transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'transactions.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'deleteTransaction',
                description: '/docs/references/databases/delete-transaction.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
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

        if (!$dbForProject->deleteDocument('transactions', $transactionId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove transaction from DB');
        }

        $dbForProject->deleteDocuments('transactionLogs', [
            Query::equal('transactionInternalId', [$transaction->getSequence()]),
        ]);

        $response->noContent();
    }
}
