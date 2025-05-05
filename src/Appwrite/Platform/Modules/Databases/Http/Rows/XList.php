<?php

namespace Appwrite\Platform\Modules\Databases\Http\Rows;

use Appwrite\Auth\Auth;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listRows';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/documents')
            ->desc('List rows')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'rows',
                name: 'listRows',
                description: '/docs/references/databases/list-documents.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_ROW_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, array $queries, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId));

        if ($table->isEmpty() || (!$table->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $rowId = $cursor->getValue();

            $cursorRow = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId));

            if ($cursorRow->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Row '{$rowId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorRow);
        }
        try {
            $rows = $dbForProject->find('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $queries);
            $total = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $queries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        }

        $operations = 0;

        // Add $tableId and $databaseId for all rows
        $processRow = (function (Document $table, Document $row) use (&$processRow, $dbForProject, $database, &$operations): bool {
            if ($row->isEmpty()) {
                return false;
            }

            $operations++;

            $row->removeAttribute('$collection');
            $row->setAttribute('$databaseId', $database->getId());
            $row->setAttribute('$collectionId', $table->getId());

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $row->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    if (\in_array(\gettype($related), ['array', 'object'])) {
                        $operations++;
                    }

                    continue;
                }

                if (!\is_array($related)) {
                    $relations = [$related];
                } else {
                    $relations = $related;
                }

                $relatedTableId = $relationship->getAttribute('relatedCollection');
                // todo: Use local cache for this getDocument
                $relatedTable = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId));

                foreach ($relations as $index => $doc) {
                    if ($doc instanceof Document) {
                        if (!$processRow($relatedTable, $doc)) {
                            unset($relations[$index]);
                        }
                    }
                }

                if (\is_array($related)) {
                    $row->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } elseif (empty($relations)) {
                    $row->setAttribute($relationship->getAttribute('key'), null);
                }
            }

            return true;
        });

        foreach ($rows as $row) {
            $processRow($table, $row);
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $select = \array_reduce($queries, function ($result, $query) {
            return $result || ($query->getMethod() === Query::TYPE_SELECT);
        }, false);

        // Check if the SELECT query includes $databaseId and $collectionId
        $hasDatabaseId = false;
        $hasTableId = false;
        if ($select) {
            $hasDatabaseId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$databaseId', $query->getValues()));
            }, false);
            $hasTableId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$collectionId', $query->getValues()));
            }, false);
        }

        if ($select) {
            foreach ($rows as $row) {
                if (!$hasDatabaseId) {
                    $row->removeAttribute('$databaseId');
                }
                if (!$hasTableId) {
                    $row->removeAttribute('$collectionId');
                }
            }
        }

        $response->dynamic(new Document([
            'total' => $total,
            'rows' => $rows,
        ]), UtopiaResponse::MODEL_ROW_LIST);
    }
}
