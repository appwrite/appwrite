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
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getRow';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows/:rowId')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId')
            ->desc('Get row')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'rows',
                name: 'getRow',
                description: '/docs/references/databases/get-document.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_ROW,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $rowId, array $queries, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId));

        if ($table->isEmpty() || (!$table->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
            $row = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId, $queries);
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if ($row->isEmpty()) {
            throw new Exception(Exception::ROW_NOT_FOUND);
        }

        $operations = 0;

        // Add $tableId and $databaseId for all rows
        $processRow = function (Document $table, Document $row) use (&$processRow, $dbForProject, $database, &$operations) {
            if ($row->isEmpty()) {
                return;
            }

            $operations++;

            $row->setAttribute('$databaseId', $database->getId());
            $row->setAttribute('$collectionId', $table->getId());

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($column) => $column->getAttribute('type') === Database::VAR_RELATIONSHIP
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
                    $related = [$related];
                }

                $relatedTableId = $relationship->getAttribute('relatedCollection');
                $relatedTable = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processRow($relatedTable, $relation);
                    }
                }
            }
        };

        $processRow($table, $row);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $response->dynamic($row, UtopiaResponse::MODEL_ROW);
    }
}
