<?php

namespace Appwrite\Platform\Modules\Databases\Http\Indexes;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Indexes;
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
        return 'listIndexes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/indexes')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/indexes')
            ->desc('List indexes')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'indexes',
                name: 'listIndexes',
                description: '/docs/references/databases/list-indexes.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_INDEX_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('queries', [], new Indexes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Indexes::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, array $queries, UtopiaResponse $response, Database $dbForProject): void
    {
        /** @var Document $database */
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
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$tableId]),
        );

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $indexId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('indexes', [
                Query::equal('collectionInternalId', [$table->getInternalId()]),
                Query::equal('databaseInternalId', [$database->getInternalId()]),
                Query::equal('key', [$indexId]),
                Query::limit(1)
            ]));

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Index '{$indexId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        try {
            $total = $dbForProject->count('indexes', $filterQueries, APP_LIMIT_COUNT);
            $indexes = $dbForProject->find('indexes', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        }

        $response->dynamic(new Document([
            'total' => $total,
            'indexes' => $indexes,
        ]), UtopiaResponse::MODEL_INDEX_LIST);
    }
}
