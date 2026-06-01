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
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

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
                    ),
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID.', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. Same shape as listRows.', true)
            ->param('total', true, new Boolean(true), 'When true, the explain captures the COUNT(*) call listRows fires for the total field as a second entry. Mirrors listRows default behavior.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param  array<string>  $queries
     */
    public function action(
        string $databaseId,
        string $collectionId,
        array $queries,
        bool $includeTotal,
        UtopiaResponse $response,
        Database $dbForProject,
        User $user,
        callable $getDatabasesDB,
        Authorization $authorization,
    ): void {
        // Reuses the same prep listRows runs (auth, lookups, query parse,
        // cursor, find closure) so the explain endpoint stays byte-identical
        // to the read it's explaining.
        $context = $this->prepareListContext($databaseId, $collectionId, $queries, $dbForProject, $user, $getDatabasesDB, $authorization);
        $database = $context['database'];
        $collection = $context['collection'];
        $dbForDatabases = $context['dbForDatabases'];
        $queries = $context['queries'];
        $collectionTableId = $context['collectionTableId'];
        $find = $context['find'];

        // listRows fires both find() and count() when `total: true` (the default).
        // Mirror that exactly so explain reflects real listRows read volume.
        $scope = function () use ($find, $includeTotal, $dbForDatabases, $collectionTableId, $queries): void {
            $find();
            if ($includeTotal) {
                $dbForDatabases->count($collectionTableId, $queries, APP_LIMIT_COUNT);
            }
        };

        $plan = null;
        try {
            // Rows are intentionally discarded — we only want the captured plan.
            $dbForDatabases->withExplain($scope, $plan);
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
     * @param  array<int, array<string, mixed>>  $entries
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
        $databaseCollectionsTable = 'database_'.$databaseSequence;
        $collectionResolver = $this->buildCollectionResolver($database, $collection, $dbForProject, $authorization);

        $output = [];
        foreach ($entries as $entry) {
            $context = $entry['context'] ?? [];
            $physicalCollection = $context['collection'] ?? null;

            if (\is_string($physicalCollection) && \str_starts_with($physicalCollection, $databaseCollectionsTable.'_collection_')) {
                $relatedSequence = \substr($physicalCollection, \strlen($databaseCollectionsTable.'_collection_'));
                $context['collection'] = $collectionResolver($relatedSequence) ?? $physicalCollection;
            }

            $output[] = new Document([
                'purpose' => $entry['purpose'] ?? 'find',
                'context' => $context,
                'plan' => $this->normalizePlan($entry['plan'] ?? []),
            ]);
        }

        return $output;
    }

    /**
     * Project the library plan onto the fixed QueryPlanDetail shape.
     *
     * The library plan also carries `engine` and the raw vendor `tree`; both are
     * intentionally dropped here so the public response never leaks the backing
     * database engine or its internal plan structure. Only the normalized,
     * engine-agnostic metrics (and an `error` when EXPLAIN failed) are exposed.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function normalizePlan(array $plan): array
    {
        return [
            'rowsScanned' => $plan['rowsScanned'] ?? null,
            'indexUsed' => $plan['indexUsed'] ?? null,
            'estimatedCost' => $plan['estimatedCost'] ?? null,
            'rowsReturned' => $plan['rowsReturned'] ?? null,
            'executionTime' => $plan['executionTime'] ?? null,
            'error' => $plan['error'] ?? null,
        ];
    }

    protected function buildCollectionResolver(
        Document $database,
        Document $primary,
        Database $dbForProject,
        Authorization $authorization,
    ): callable {
        $cache = [
            (string) $primary->getSequence() => $primary->getId(),
        ];
        $databaseCollectionsTable = 'database_'.$database->getSequence();

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
