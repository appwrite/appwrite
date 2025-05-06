<?php

namespace Appwrite\Platform\Modules\Databases\Http\Rows;

use Appwrite\Auth\Auth;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteRow';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows/:rowId')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId')
            ->desc('Delete row')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].delete')
            ->label('audits.event', 'row.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}/row/{request.rowId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'rows',
                name: 'deleteRow',
                description: '/docs/references/databases/delete-document.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $rowId, ?\DateTime $requestTimestamp, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage): void
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

        // Read permission should not be required for delete
        $row = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId));

        if ($row->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $database, $table, $rowId) {
                $dbForProject->deleteDocument(
                    'database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(),
                    $rowId
                );
            });
        } catch (NotFoundException) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        // Add $tableId and $databaseId for all rows
        $processRow = function (Document $table, Document $row) use (&$processRow, $dbForProject, $database) {
            $row->setAttribute('$databaseId', $database->getId());
            $row->setAttribute('$collectionId', $table->getId());

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $row->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
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
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1); // per collection

        $response->addHeader('X-Debug-Operations', 1);

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('rowId', $row->getId())
            ->setContext('table', $table)
            ->setContext('database', $database)
            ->setPayload($response->output($row, UtopiaResponse::MODEL_ROW), sensitive: $relationships);

        $response->noContent();
    }
}
