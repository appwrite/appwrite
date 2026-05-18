<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Explain;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

/**
 * Base implementation for the explain-rows / explain-documents endpoint.
 *
 * Mirrors the shape of `XList` (listDocuments / listRows) but instead of
 * executing the underlying read, captures the vendor-native query plan for
 * each read Appwrite would have issued. Customer-facing output is sanitized
 * so internal storage details (the `_perms` companion table, `_metadata`
 * system table, internal column names) never leak.
 *
 * Not registered directly — TablesDB (and any future namespace) subclasses
 * this and overrides the constructor with the namespace-specific URL + SDK
 * metadata. See TablesDB\Tables\Rows\Explain\Get.
 */
abstract class Get extends Action
{
    public static function getName(): string
    {
        return 'explainRows';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_QUERY_PLAN;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/explain')
            ->desc('Explain rows query plan')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/explain-rows.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID.', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. Same shape as listRows.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param string $databaseId
     * @param string $collectionId
     * @param array<string> $queries
     */
    public function action(
        string $databaseId,
        string $collectionId,
        array $queries,
        UtopiaResponse $response,
        Database $dbForProject,
        User $user,
        callable $getDatabasesDB,
        Authorization $authorization,
    ): void {
        $isAPIKey = $user->isApp($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $dbForDatabases = $getDatabasesDB($database);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $documentId = $cursor->getValue();

            $cursorDocument = $authorization->skip(fn () => $dbForDatabases->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));

            if ($cursorDocument->isEmpty()) {
                $type = ucfirst($this->getContext());
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "$type '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
        $hasSelects = !empty(Query::groupByType($queries)['selections']);

        // Mirror listRows: skip relationship resolution when the caller didn't
        // ask for related selects, to avoid capturing plans for reads the real
        // endpoint would not have issued either.
        $find = $hasSelects
            ? fn () => $dbForDatabases->find($collectionTableId, $queries)
            : fn () => $dbForDatabases->skipRelationships(fn () => $dbForDatabases->find($collectionTableId, $queries));

        try {
            $plan = $dbForDatabases->withExplain($find);
        } catch (OrderException $e) {
            $documents = $this->isCollectionsAPI() ? 'documents' : 'rows';
            $attribute = $this->isCollectionsAPI() ? 'attribute' : 'column';
            $message = "The order $attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all $documents order $attribute values are non-null.";
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, $message);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        } catch (Timeout) {
            throw new Exception(Exception::DATABASE_TIMEOUT);
        }

        $translated = $this->translatePlanCollections(
            $plan->getAttribute('queries', []),
            $database,
            $collection,
            $dbForProject,
            $authorization,
        );

        $response->dynamic(new Document([
            'queries' => $translated,
        ]), $this->getResponseModel());
    }

    /**
     * Walk the captured plans and rewrite internal `database_<seq>_collection_<seq>`
     * references to the user-facing collection ID. Each captured entry came from
     * a real find() invocation, so the `context.collection` field always carries
     * the physical table id; this maps it back to the customer's vocabulary
     * (and resolves related collections by sequence for relationship fetches).
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, Document>
     */
    protected function translatePlanCollections(
        array $entries,
        Document $database,
        Document $collection,
        Database $dbForProject,
        Authorization $authorization,
    ): array {
        $databaseSequence = $database->getSequence();
        $databaseCollectionsTable = 'database_' . $databaseSequence;
        $collectionResolver = $this->buildCollectionResolver($database, $collection, $dbForProject, $authorization);

        $output = [];
        foreach ($entries as $entry) {
            $context = $entry['context'] ?? [];
            $physicalCollection = $context['collection'] ?? null;

            if (\is_string($physicalCollection) && \str_starts_with($physicalCollection, $databaseCollectionsTable . '_collection_')) {
                $relatedSequence = \substr($physicalCollection, \strlen($databaseCollectionsTable . '_collection_'));
                $context['collection'] = $collectionResolver($relatedSequence) ?? $physicalCollection;
            }

            $output[] = new Document([
                'purpose' => $entry['purpose'] ?? 'find',
                'context' => $context,
                'plan'    => $entry['plan'] ?? [],
            ]);
        }

        return $output;
    }

    /**
     * Returns a closure that maps a collection $sequence to its user-facing id,
     * memoizing lookups for repeat hits during relationship resolution.
     */
    protected function buildCollectionResolver(
        Document $database,
        Document $primary,
        Database $dbForProject,
        Authorization $authorization,
    ): callable {
        $cache = [
            (string) $primary->getSequence() => $primary->getId(),
        ];
        $databaseCollectionsTable = 'database_' . $database->getSequence();

        return function (string $sequence) use (&$cache, $databaseCollectionsTable, $dbForProject, $authorization): ?string {
            if (\array_key_exists($sequence, $cache)) {
                return $cache[$sequence];
            }
            $related = $authorization->skip(fn () => $dbForProject->findOne($databaseCollectionsTable, [
                Query::equal('$sequence', [$sequence]),
            ]));
            $resolved = $related->isEmpty() ? null : $related->getId();
            $cache[$sequence] = $resolved;
            return $resolved;
        };
    }
}
