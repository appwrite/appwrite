<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Explanation;

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
        return 'getExplanation';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_EXPLANATION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/explanation')
            ->desc('Get documents explanation')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('usage.resource', 'database/{request.databaseId}/collection/{request.collectionId}/documents')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: 'getExplanation',
                description: '/docs/references/databases/explanation-documents.md',
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
            ->param('total', true, new Boolean(true), 'When true, the explanation captures the COUNT(*) call listDocuments fires for the total field as a second entry. Mirrors listDocuments default behavior.', true)
            ->param('tree', false, new Boolean(true), 'When true, include the sanitized backend-specific query plan tree. Defaults to false so the tree field is omitted.', true)
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
        bool $includeTree,
        UtopiaResponse $response,
        Database $dbForProject,
        User $user,
        callable $getDatabasesDB,
        Context $usage,
        Authorization $authorization,
    ): void {
        $context = $this->prepareListContext($databaseId, $collectionId, $queries, $dbForProject, $user, $getDatabasesDB, $authorization);
        $database = $context['database'];
        $collection = $context['collection'];
        $dbForDatabases = $context['dbForDatabases'];
        $queries = $context['queries'];
        $collectionTableId = $context['collectionTableId'];
        $find = $context['find'];

        $scope = function () use ($find, $includeTotal, $dbForDatabases, $collectionTableId, $queries): void {
            $find();
            if ($includeTotal) {
                $dbForDatabases->count($collectionTableId, $queries, APP_LIMIT_COUNT);
            }
        };

        $plan = new Document(['queries' => []]);
        try {
            if (!\method_exists($dbForDatabases, 'withExplain')) {
                throw new Exception(Exception::GENERAL_UNKNOWN, 'Query explanation is not supported by this database adapter.');
            }

            // utopia-php/database 7.3.11 does not declare withExplain yet.
            // feat/explain adds it; call by name so the by-ref plan out-param
            // still works and PHPStan on the released type stays clean.
            $method = 'withExplain';
            $dbForDatabases->$method($scope, $plan);
        } catch (NotFoundException) {
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
        $operations = \max(\count($entries), 1);
        $usage
            ->setResource('database')
            ->setResourceId($database->getId())
            ->setResourceInternalId((string) $database->getSequence())
            ->addMetric($this->getDatabasesOperationReadMetric(), $operations);

        $translated = $this->translatePlanCollections(
            $entries,
            $database,
            $collection,
            $dbForProject,
            $authorization,
            $includeTree,
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
        bool $includeTree,
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
                $context['collection'] = $collectionResolver($relatedSequence);
            }

            $output[] = new Document([
                'purpose' => $entry['purpose'] ?? 'find',
                'context' => $this->normalizeContext($context),
                'plan' => $this->normalizePlan($entry['plan'] ?? [], $includeTree),
            ]);
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function normalizeContext(array $context): array
    {
        $normalized = [
            'collection' => isset($context['collection'])
                ? $this->scrubPhysicalIdentifiers($context['collection'])
                : null,
        ];

        if (isset($context['attribute'])) {
            $normalized['attribute'] = $this->scrubPhysicalIdentifiers($context['attribute']);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function normalizePlan(array $plan, bool $includeTree): array
    {
        $indexUsed = isset($plan['indexUsed']) ? $this->scrubPhysicalIdentifiers($plan['indexUsed']) : null;
        $rawTree = $plan['tree'] ?? null;
        $tree = $includeTree && isset($plan['tree']) ? $this->scrubPhysicalIdentifiers($plan['tree']) : null;

        return [
            'metrics' => [
                'estimatedRecordsScanned' => $plan['rowsScanned'] ?? null,
                'recordsReturned' => $plan['rowsReturned'] ?? null,
                'durationMs' => $plan['executionTime'] ?? null,
                'estimatedCost' => $plan['estimatedCost'] ?? null,
            ],
            'access' => [
                'type' => $this->inferAccessType($indexUsed, $rawTree),
                'index' => $indexUsed,
            ],
            'rowsScanned' => $plan['rowsScanned'] ?? null,
            'indexUsed' => $indexUsed,
            'estimatedCost' => $plan['estimatedCost'] ?? null,
            'rowsReturned' => $plan['rowsReturned'] ?? null,
            'executionTime' => $plan['executionTime'] ?? null,
            'tree' => $includeTree ? $tree : null,
            'error' => isset($plan['error']) ? $this->scrubPhysicalIdentifiers($plan['error']) : null,
        ];
    }

    protected function inferAccessType(mixed $indexUsed, mixed $tree): string
    {
        if (\is_string($indexUsed) && $indexUsed !== '') {
            return 'index_scan';
        }

        if ($this->containsPlanToken($tree, ['COLLSCAN', 'Seq Scan', 'ALL'])) {
            return 'full_scan';
        }

        return 'unknown';
    }

    /**
     * @param  array<string>  $needles
     */
    protected function containsPlanToken(mixed $node, array $needles): bool
    {
        if (\is_array($node)) {
            foreach ($node as $key => $value) {
                if ($this->containsPlanToken($key, $needles) || $this->containsPlanToken($value, $needles)) {
                    return true;
                }
            }

            return false;
        }

        if (! \is_string($node)) {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle === 'ALL') {
                if ($node === $needle) {
                    return true;
                }

                continue;
            }

            if (\str_contains($node, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse backend-specific table/index/namespace tokens to placeholders.
     *
     * Physical names vary by engine (MariaDB sequences, Mongo UUIDs, Postgres
     * relations) and appear nested inside plan strings. String functions cannot
     * match those variable-length tokens without a pattern language, so this
     * sanitizer uses preg_replace only for that identifier rewrite.
     */
    protected function scrubPhysicalIdentifiers(mixed $node): mixed
    {
        if (\is_array($node)) {
            $result = [];
            foreach ($node as $key => $value) {
                if (\is_string($key) && $this->shouldDropPlanTreeKey($key)) {
                    continue;
                }

                if (\is_string($key) && \is_string($value)) {
                    $masked = $this->maskPlanTreeValue($key, $value);
                    if ($masked !== null) {
                        $result[$key] = $masked;

                        continue;
                    }
                }

                $result[$key] = $this->scrubPhysicalIdentifiers($value);
            }

            return $result;
        }

        if (! \is_string($node)) {
            return $node;
        }

        $patterns = [
            '/(?:_\d+_)?database_[\w-]+_collection_[\w-]+_perms\b/i' => '<permissionCheck>',
            '/(?:_\d+_)?database_[\w-]+_collection_[\w-]+_permission\b/i' => '<permissionCheck>',
            '/(?:_\d+_)?database_[\w-]+__metadata\b/i' => '<metadata>',
            '/\b[a-f0-9]{32}_[A-Za-z][\w-]*\b/i' => '<index>',
            '/_index_[A-Za-z][\w-]*\b/i' => '<index>',
            '/_\d+_\d+\b/i' => '<collection>',
            '/_\d+_[\w-]{16,}_[\w-]{16,}\b/i' => '<collection>',
            '/_[\w-]{16,}_[\w-]{16,}\b/i' => '<collection>',
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

    protected function maskPlanTreeValue(string $key, string $value): ?string
    {
        return match (\strtolower($key)) {
            'namespace', 'ns' => \preg_replace('/^[^.]+\\./', '', $this->scrubPhysicalIdentifiers($value)) ?? $value,
            'database', 'dbname', 'db' => '<database>',
            'collection', 'collectionname', 'relation name', 'table', 'tablename', 'table_name' => '<collection>',
            'index', 'indexname', 'index name' => '<index>',
            'host', 'server', 'address' => '<server>',
            default => null,
        };
    }

    protected function shouldDropPlanTreeKey(string $key): bool
    {
        return \in_array(\strtolower($key), [
            '$db',
            '$clustertime',
            'operationtime',
            'serverinfo',
            'serverparameters',
            'slotbasedplan',
            'engine',
            'hasengine',
            'port',
            'topologyversion',
            'signature',
        ], true);
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
