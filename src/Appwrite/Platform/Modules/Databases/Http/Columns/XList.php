<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Columns;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listColumns';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes')
            ->desc('List columns')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'listColumns',
                description: '/docs/references/databases/list-attributes.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_COLUMN_LIST
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('queries', [], new Columns(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Columns::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, array $queries, UtopiaResponse $response, Database $dbForProject): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        if ($table->isEmpty()) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        \array_push(
            $queries,
            Query::equal('databaseInternalId', [$database->getInternalId()]),
            Query::equal('collectionInternalId', [$table->getInternalId()])
        );

        $cursor = \array_filter(
            $queries,
            fn ($query) => \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE])
        );
        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $columnId = $cursor->getValue();
            $cursorDocument = Authorization::skip(
                fn () => $dbForProject->find('attributes', [
                    Query::equal('databaseInternalId', [$database->getInternalId()]),
                    Query::equal('collectionInternalId', [$table->getInternalId()]),
                    Query::equal('key', [$columnId]),
                    Query::limit(1),
                ])
            );

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Column '{$columnId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $filters = Query::groupByType($queries)['filters'];

        try {
            $columns = $dbForProject->find('attributes', $queries);
            $total = $dbForProject->count('attributes', $filters, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        }

        $response->dynamic(new Document([
            'columns' => $columns,
            'total' => $total,
        ]), UtopiaResponse::MODEL_COLUMN_LIST);
    }
}
