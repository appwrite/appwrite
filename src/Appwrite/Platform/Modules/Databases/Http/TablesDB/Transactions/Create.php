<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions;

use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Create as TransactionsCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Range;

class Create extends TransactionsCreate
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
            ->setHttpPath('/v1/tablesdb/transactions')
            ->desc('Create transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', ['documents.write', 'rows.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'tablesDB',
                group: 'transactions',
                name: 'createTransaction',
                description: '/docs/references/tablesdb/create-transaction.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
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
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
