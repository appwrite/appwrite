<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions;

use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\XList as TransactionsList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Transactions;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class XList extends TransactionsList
{
    public static function getName(): string
    {
        return 'listVectorsDBTransactions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vectorsdb/transactions')
            ->desc('List transactions')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'vectorsdb.documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'vectorsDB',
                group: 'transactions',
                name: 'listTransactions',
                description: '/docs/references/vectorsdb/list-transactions.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_TRANSACTION_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('queries', [], new Transactions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries).', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
