<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Transactions;

use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Update as TransactionsUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class Update extends TransactionsUpdate
{
    public static function getName(): string
    {
        return 'updateVectorDBTransaction';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/vectordb/transactions/:transactionId')
            ->desc('Update transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'vectorDB',
                group: 'transactions',
                name: 'updateTransaction',
                description: '/docs/references/vectordb/update-transaction.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('transactionId', '', new UID(), 'Transaction ID.')
            ->param('commit', false, new Boolean(), 'Commit transaction?', true)
            ->param('rollback', false, new Boolean(), 'Rollback transaction?', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('user')
            ->inject('transactionState')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('queueForRealtime')
            ->inject('queueForFunctions')
            ->inject('queueForWebhooks')
            ->inject('authorization')
            ->inject('eventProcessor')
            ->callback($this->action(...));
    }
}
