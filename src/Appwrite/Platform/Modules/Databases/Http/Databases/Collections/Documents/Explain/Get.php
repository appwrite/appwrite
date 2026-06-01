<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Explain;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

abstract class Get extends Action
{
    // Each concrete surface (TablesDB/DocumentsDB/VectorsDB) defines its own
    // getName() + __construct() with the correct route, scope, and SDK metadata.
    // This base only holds the shared response model and action logic.

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_QUERY_PLAN;
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
        } catch (NotFoundException) {
            // The collection metadata document exists but the backing store has
            // no table for it. Mirror listRows: surface a 404, not a 500.
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
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

        // withExplain populates $plan via its finally; stay null-safe regardless.
        $translated = $this->translatePlanCollections(
            $plan?->getAttribute('queries', []) ?? [],
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
                'context' => $this->normalizeContext($context),
                'plan' => $this->normalizePlan($entry['plan'] ?? [], $databaseCollectionsTable, $collectionResolver),
            ]);
        }

        return $output;
    }

    /**
     * Whitelist the context to the user-facing identifiers we intend to expose.
     *
     * Like normalizePlan(), this prevents any future key the library adds to the
     * capture context from leaking unfiltered into the public response.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function normalizeContext(array $context): array
    {
        $normalized = ['collection' => $context['collection'] ?? null];

        // Only sum captures carry an attribute; omit it otherwise.
        if (isset($context['attribute'])) {
            $normalized['attribute'] = $context['attribute'];
        }

        return $normalized;
    }

    /**
     * Project the library plan onto the fixed QueryPlanDetail shape.
     *
     * The library plan also carries `engine`, which is dropped so the response
     * never advertises the backing database engine. The sanitized `tree` is
     * kept — it holds the access-path detail customers need for deep diagnosis.
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(string): ?string  $collectionResolver
     * @return array<string, mixed>
     */
    protected function normalizePlan(array $plan, string $databaseCollectionsTable, callable $collectionResolver): array
    {
        return [
            'rowsScanned' => $plan['rowsScanned'] ?? null,
            'indexUsed' => $plan['indexUsed'] ?? null,
            'estimatedCost' => $plan['estimatedCost'] ?? null,
            'rowsReturned' => $plan['rowsReturned'] ?? null,
            'executionTime' => $plan['executionTime'] ?? null,
            'tree' => isset($plan['tree']) ? $this->scrubTreeIdentifiers($plan['tree'], $databaseCollectionsTable, $collectionResolver) : null,
            'error' => $plan['error'] ?? null,
        ];
    }

    /**
     * Rewrite physical collection identifiers in the raw plan tree back to the
     * user-facing collection id. The library strips internal column names but
     * leaves physical table/namespace references (e.g. the MariaDB `table_name`
     * or the Mongo `namespace`), which embed `database_<seq>_collection_<seq>`.
     *
     * @param  callable(string): ?string  $collectionResolver
     */
    protected function scrubTreeIdentifiers(mixed $node, string $databaseCollectionsTable, callable $collectionResolver): mixed
    {
        if (\is_array($node)) {
            $result = [];
            foreach ($node as $key => $value) {
                $result[$key] = $this->scrubTreeIdentifiers($value, $databaseCollectionsTable, $collectionResolver);
            }

            return $result;
        }

        if (\is_string($node) && \str_contains($node, $databaseCollectionsTable.'_collection_')) {
            $needle = $databaseCollectionsTable.'_collection_';

            return \preg_replace_callback(
                '/'.\preg_quote($needle, '/').'([0-9a-f-]+)/i',
                fn (array $m) => $collectionResolver($m[1]) ?? $m[0],
                $node,
            );
        }

        return $node;
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
