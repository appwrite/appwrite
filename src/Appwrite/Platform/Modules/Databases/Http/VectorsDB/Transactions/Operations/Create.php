<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Operations;

use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Operations\Create as OperationsCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Operation;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;

class Create extends OperationsCreate
{
    public static function getName(): string
    {
        return 'createVectorsDBOperations';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vectorsdb/transactions/:transactionId/operations')
            ->desc('Create operations')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'vectorsDB',
                group: 'transactions',
                name: 'createOperations',
                description: '/docs/references/vectorsdb/create-operations.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('transactionId', '', new UID(), 'Transaction ID.')
            ->param('operations', [], new ArrayList(new Operation(type: 'documentsdb')), 'Array of staged operations.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }
}
