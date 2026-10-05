<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Transactions;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class XList extends Action
{
    public static function getName(): string
    {
        return 'listDatabasesTransactions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/transactions')
            ->desc('List transactions')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'rows.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'listTransactions',
                description: '/docs/references/databases/list-transactions.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_TRANSACTION_LIST,
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.listTransactions',
                )
            ))
            ->param('queries', [], new Transactions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries).', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(array $queries, bool $includeTotal, UtopiaResponse $response, Database $dbForProject): void
    {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $response->dynamic(new Document([
            'transactions' => $dbForProject->find('transactions', $queries),
            'total' => $includeTotal ? $dbForProject->count('transactions', $queries) : 0,
        ]), UtopiaResponse::MODEL_TRANSACTION_LIST);
    }
}
