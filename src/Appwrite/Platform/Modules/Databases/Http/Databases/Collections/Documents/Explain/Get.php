<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Explain;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
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
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Get extends Action
{
    public static function getName(): string
    {
        return 'explainDocuments';
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
            ->desc('Explain documents query plan')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/explain-documents.md',
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
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. Same shape as listDocuments.', true)
            ->param('total', true, new Boolean(true), 'When true, the explain captures the COUNT(*) call listDocuments fires for the total field as a second entry. Mirrors listDocuments default behavior.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('usage')
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
        Context $usage,
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

        // withExplain overwrites $plan via its by-ref out-param; seed it with an
        // empty plan so the response is well-formed even if nothing was captured.
        $plan = new Document(['queries' => []]);
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

        $entries = $plan->getAttribute('queries', []);

        // Explain runs the real find()/count(), so it must be metered like
        // listRows. One captured entry == one physical read; floor at 1 so a
        // scan can never be free of quota (matches XList's max($operations, 1)).
        $operations = \max(\count($entries), 1);
        $usage
            ->addMetric($this->getDatabasesOperationReadMetric(), $operations)
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), $this->getDatabasesIdOperationReadMetric()), $operations);

        $translated = $this->translatePlanCollections(
            $entries,
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
        $databaseInternalId = (string) $database->getSequence();
        $databaseCollectionsTable = 'database_'.$databaseInternalId;
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
                'plan' => $this->normalizePlan($entry['plan'] ?? []),
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
     * @return array<string, mixed>
     */
    protected function normalizePlan(array $plan): array
    {
        return [
            'rowsScanned' => $plan['rowsScanned'] ?? null,
            'indexUsed' => isset($plan['indexUsed']) ? $this->scrubPhysicalIdentifiers($plan['indexUsed']) : null,
            'estimatedCost' => $plan['estimatedCost'] ?? null,
            'rowsReturned' => $plan['rowsReturned'] ?? null,
            'executionTime' => $plan['executionTime'] ?? null,
            'tree' => isset($plan['tree']) ? $this->scrubPhysicalIdentifiers($plan['tree']) : null,
            'error' => isset($plan['error']) ? $this->scrubPhysicalIdentifiers($plan['error']) : null,
        ];
    }

    /**
     * Replace internal storage identifiers the engine leaves in the raw plan
     * with generic placeholders.
     *
     * The library renames internal columns, but physical table/index/relation
     * names still leak across engines as `[_<tenant>_]database_<dbSeq>_collection_<collSeq>`
     * with optional `_perms`/`_permission`/`_metadata` suffixes. We don't resolve
     * these back to user ids (the entry's `context.collection` already carries
     * that) — we just collapse any physical token to a placeholder so the public
     * tree never exposes internal naming, tenant ids, or sequences. Walks
     * recursively over arrays and strings.
     */
    protected function scrubPhysicalIdentifiers(mixed $node): mixed
    {
        if (\is_array($node)) {
            $result = [];
            foreach ($node as $key => $value) {
                $result[$key] = $this->scrubPhysicalIdentifiers($value);
            }

            return $result;
        }

        if (! \is_string($node)) {
            return $node;
        }

        // Sequences are numeric (MariaDB/Postgres) or hyphenated UUIDs (Mongo),
        // so the id class allows word chars and hyphens. Order matters: match the
        // longest/most-specific suffix first so a perms or metadata table is not
        // first collapsed to a bare <collection>.
        $patterns = [
            '/(?:_\d+_)?database_[\w-]+_collection_[\w-]+_perms\b/i' => '<permissionCheck>',
            '/(?:_\d+_)?database_[\w-]+_collection_[\w-]+_permission\b/i' => '<permissionCheck>',
            '/(?:_\d+_)?database_[\w-]+__metadata\b/i' => '<metadata>',
            '/_\d+_[\w-]{16,}_(?:permission|perms)\b/i' => '<permissionCheck>',
            '/_\d+_[\w-]{16,}_ukey\b/i' => '<index>',
            '/_\d+_[\w-]{16,}_[A-Za-z][\w-]*\b/i' => '<index>',
            '/_\d+_[\w-]{16,}\b/i' => '<collection>',
            '/_[\w-]{16,}_(?:permission|perms)\b/i' => '<permissionCheck>',
            '/_[\w-]{16,}_ukey\b/i' => '<index>',
            '/_[\w-]{16,}_[A-Za-z][\w-]*\b/i' => '<index>',
            '/_[\w-]{16,}\b/i' => '<collection>',
            '/(?:_\d+_)?database_[\w-]+_collection_[\w-]+\b/i' => '<collection>',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $node = \preg_replace($pattern, $replacement, $node) ?? $node;
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
