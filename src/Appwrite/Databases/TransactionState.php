<?php

namespace Appwrite\Databases;

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\ParsedQuery;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\CursorDirection;
use Utopia\Query\Method;
use Utopia\Query\OrderDirection;

/**
 * Service for managing transaction state and providing transaction-aware document operations
 *
 * This class provides methods to:
 * - Query documents with transaction awareness (getDocument, listDocuments, countDocuments)
 * - Apply bulk operations to transaction state for cross-operation visibility
 * - Replay transaction operations to build current state
 */
class TransactionState
{
    private const int DEFAULT_LIMIT = 25;

    private const string PENDING_TIMESTAMP = '9999-12-31T23:59:59.999+00:00';

    private Database $dbForProject;
    private Authorization $authorization;
    /**
     * @var callable(Document $database): Database
     */
    private mixed $getDatabasesDB;
    private User $user;

    public function __construct(Database $dbForProject, Authorization $authorization, callable $getDatabasesDB, User $user)
    {
        $this->dbForProject = $dbForProject;
        $this->authorization = $authorization;
        $this->getDatabasesDB = $getDatabasesDB;
        $this->user = $user;
    }


    /**
     * Get a document with transaction-aware logic
     *
     * @param Document $database Target database document
     * @param string $collectionId Collection ID
     * @param string $documentId Document ID
     * @param string|null $transactionId Optional transaction ID
     * @param array $queries Optional query filters
     * @return Document
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    public function getDocument(
        Document $database,
        string $collectionId,
        string $documentId,
        ?string $transactionId = null,
        array $queries = []
    ): Document {
        $dbForDatabases = ($this->getDatabasesDB)($database);
        if ($transactionId === null) {
            return $dbForDatabases->getDocument($collectionId, $documentId, $queries);
        }

        $state = $this->getTransactionState($transactionId);

        if (isset($state[$collectionId][$documentId])) {
            $docState = $state[$collectionId][$documentId];

            if (!$docState['exists']) {
                return new Document();
            }

            if ($docState['action'] === 'create') {
                return $this->applyProjection($docState['document'], $queries);
            }

            if ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                // Merge with committed version
                $committedDoc = $dbForDatabases->getDocument($collectionId, $documentId, $queries);
                if (!$committedDoc->isEmpty()) {
                    foreach ($docState['document']->getAttributes() as $key => $value) {
                        if ($key !== '$id') {
                            $committedDoc->setAttribute($key, $value);
                        }
                    }
                    // Reapply projection in case transaction added new fields
                    return $this->applyProjection($committedDoc, $queries);
                } elseif ($docState['action'] === 'upsert') {
                    return $this->applyProjection($docState['document'], $queries);
                }
            }
        }
        return $dbForDatabases->getDocument($collectionId, $documentId, $queries);
    }

    /**
     * List documents with transaction-aware logic
     *
     * @param Document $database Target database document
     * @param string $collectionId Collection ID
     * @param string|null $transactionId Optional transaction ID
     * @param array $queries Optional query filters
     * @return array Array of Document objects
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    public function listDocuments(
        Document $database,
        string $collectionId,
        ?string $transactionId = null,
        array $queries = []
    ): array {
        $dbForDatabases = ($this->getDatabasesDB)($database);
        if ($transactionId === null) {
            return $dbForDatabases->find($collectionId, $queries);
        }

        $this->assertNotAggregated($queries);

        $staged = $this->getTransactionState($transactionId)[$collectionId] ?? [];
        if ($staged === []) {
            return $dbForDatabases->find($collectionId, $queries);
        }

        $parsed = Query::groupByType($queries);
        if ($parsed->joins === [] && !$parsed->distinct) {
            return $this->listPlainDocuments($dbForDatabases, $collectionId, $staged, $queries, $parsed);
        }

        $documents = [];
        $committed = [];

        foreach ($dbForDatabases->find($collectionId, $queries) as $row) {
            $id = $row->getId();
            $document = $this->overlay($row, $staged[$id] ?? null, !isset($committed[$id]), $queries);
            $committed[$id] = true;
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        foreach ($staged as $id => $docState) {
            if (isset($committed[$id]) || !$docState['exists']) {
                continue;
            }
            if ($docState['action'] === 'create' || $docState['action'] === 'upsert') {
                $documents[] = $this->applyProjection($docState['document'], $queries);
            }
        }

        return $documents;
    }

    /**
     * A join returns one row per joined row, all carrying the main document's $id: the staged state of
     * that $id applies to every one of them, and a staged create takes the place of the first.
     *
     * @param array<string, mixed>|null $docState
     * @param array<Query> $queries
     */
    private function overlay(Document $row, ?array $docState, bool $first, array $queries): ?Document
    {
        if ($docState === null) {
            return $row;
        }
        if (!$docState['exists']) {
            return null;
        }
        if ($docState['action'] === 'create') {
            return $first ? $this->applyProjection($docState['document'], $queries) : null;
        }
        if ($docState['action'] !== 'update' && $docState['action'] !== 'upsert') {
            return $row;
        }

        foreach ($docState['document']->getAttributes() as $key => $value) {
            if ($key !== '$id') {
                $row->setAttribute($key, $value);
            }
        }

        return $this->applyProjection($row, $queries);
    }

    /**
     * Count documents with transaction-aware logic
     *
     * @param Document $database Target database document
     * @param string $collectionId Collection ID
     * @param string|null $transactionId Optional transaction ID
     * @param array $queries Optional query filters
     * @return int Document count
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    public function countDocuments(
        Document $database,
        string $collectionId,
        ?string $transactionId = null,
        array $queries = []
    ): int {
        $dbForDatabases = ($this->getDatabasesDB)($database);
        if ($transactionId === null) {
            return $dbForDatabases->count($collectionId, $queries, APP_LIMIT_COUNT);
        }

        $this->assertNotAggregated($queries);

        $state = $this->getTransactionState($transactionId);
        $baseCount = $dbForDatabases->count($collectionId, $queries, APP_LIMIT_COUNT);

        if (!isset($state[$collectionId])) {
            return $baseCount;
        }

        $parsed = Query::groupByType($queries);
        if ($parsed->joins === [] && !$parsed->distinct) {
            $view = $this->resolveStaged($dbForDatabases, $collectionId, $state[$collectionId], $parsed->filters);

            return \max(0, $baseCount - \count($view->storedMatches) + \count(\array_filter($view->matches)));
        }
        $committedRows = [];
        foreach ($dbForDatabases->find($collectionId, $queries) as $row) {
            $id = $row->getId();
            $committedRows[$id] = ($committedRows[$id] ?? 0) + 1;
        }

        $adjustedCount = $baseCount;

        $filters = $this->extractFilters($queries);

        foreach ($state[$collectionId] as $docId => $docState) {
            if (!$docState['exists']) {
                $adjustedCount -= $committedRows[$docId] ?? 0;
            } elseif ($docState['action'] === 'create') {
                if ($this->documentMatchesFilters($docState['document'], $filters)) {
                    $adjustedCount++;
                }
            } elseif ($docState['action'] === 'update' || $docState['action'] === 'upsert') {
                $wasInResults = isset($committedRows[$docId]);
                $nowMatches = $this->documentMatchesFilters($docState['document'], $filters);

                if (!$wasInResults && $nowMatches && $docState['action'] === 'upsert') {
                    $adjustedCount++;
                } elseif ($wasInResults && !$nowMatches) {
                    $adjustedCount -= $committedRows[$docId];
                } elseif (!$wasInResults && $nowMatches) {
                    // Update shouldn't add a new doc, but upsert might have
                    if ($docState['action'] === 'upsert') {
                        $adjustedCount++;
                    }
                }
            }
        }

        return max(0, $adjustedCount);
    }

    /**
     * A read without joins or distinct over the stored rows and the staged writes together: the filters are applied to
     * staged creates and to the updated rows, then the order, cursor, offset and limit to the merged set. The stored
     * rows are read past the cursor in pages large enough that dropping every staged row still leaves the page full.
     *
     * @param array<string, array<string, mixed>> $staged
     * @param array<Query> $queries
     * @return array<Document>
     */
    private function listPlainDocuments(Database $dbForDatabases, string $collectionId, array $staged, array $queries, ParsedQuery $parsed): array
    {
        $view = $this->resolveStaged($dbForDatabases, $collectionId, $staged, $parsed->filters);
        $random = \in_array(OrderDirection::Random, $parsed->orderTypes, true);
        $orders = $this->orders($parsed);
        $limit = $parsed->limit ?? self::DEFAULT_LIMIT;
        $offset = $parsed->offset ?? 0;
        $before = $parsed->cursorDirection === CursorDirection::Before;
        $cursor = $random ? null : $parsed->cursor;

        $window = \array_values(\array_filter(
            $queries,
            static fn (Query $query): bool => !\in_array($query->getMethod(), [Method::Limit, Method::Offset, Method::CursorAfter, Method::CursorBefore], true),
        ));
        $added = $this->selectOrderAttributes($parsed, $orders);
        if ($added !== []) {
            $window[] = Query::select($added);
        }

        $cursorKey = null;
        if ($cursor instanceof Document) {
            $cursorDocument = $view->documents[$cursor->getId()] ?? $cursor;
            $cursorPending = $view->pending[$cursor->getId()] ?? null;
            $cursorKey = $this->sortKey($cursorDocument, $orders, $cursorPending);
            $storedCursor = $this->storedCursor($collectionId, $cursor->getId(), $cursorDocument, $orders, $cursorPending);
            $window[] = $before ? Query::cursorBefore($storedCursor) : Query::cursorAfter($storedCursor);
        }
        $window[] = Query::limit($offset + $limit + \count($staged));

        $rows = [];
        foreach ($dbForDatabases->find($collectionId, $window) as $row) {
            if (!isset($staged[$row->getId()])) {
                $key = $this->sortKey($row, $orders, null);
                foreach ($added as $attribute) {
                    $row->removeAttribute($attribute);
                }
                $rows[] = ['document' => $row, 'key' => $key];
            }
        }

        foreach ($view->documents as $id => $document) {
            if (!$view->matches[$id]) {
                continue;
            }
            $key = $this->sortKey($document, $orders, $view->pending[$id] ?? null);
            if ($cursorKey !== null) {
                $comparison = $this->compareKeys($key, $cursorKey);
                if ($id === $cursor->getId() || ($before ? $comparison >= 0 : $comparison <= 0)) {
                    continue;
                }
            }
            $rows[] = ['document' => $this->applyProjection($document, $queries), 'key' => $key];
        }

        if (!$random) {
            \usort($rows, fn (array $a, array $b): int => $this->compareKeys($a['key'], $b['key']));
        }

        if ($before) {
            $rows = \array_reverse(\array_slice(\array_reverse($rows), $offset, $limit));
        } else {
            $rows = \array_slice($rows, $offset, $limit);
        }

        return \array_map(static fn (array $row): Document => $row['document'], $rows);
    }

    /**
     * The staged documents of one collection as a plain read sees them: a staged update laid over the stored row the
     * caller can read, a staged create or upsert of a new row as staged. Whether each matches the filters is decided by
     * the database for a stored row whose staged changes leave the filtered attributes alone, and by
     * documentMatchesFilters() otherwise.
     *
     * @param array<string, array<string, mixed>> $staged
     * @param array<Query> $filters
     */
    private function resolveStaged(Database $dbForDatabases, string $collectionId, array $staged, array $filters): StagedDocuments
    {
        $ids = \array_map(\strval(...), \array_keys($staged));

        $stored = [];
        foreach ($dbForDatabases->find($collectionId, [Query::equal('$id', $ids), Query::limit(\count($ids))]) as $row) {
            $stored[$row->getId()] = $row;
        }

        $storedMatches = [];
        if ($stored !== []) {
            $storedIds = \array_map(\strval(...), \array_keys($stored));
            foreach ($dbForDatabases->find($collectionId, [...$filters, Query::equal('$id', $storedIds), Query::limit(\count($storedIds))]) as $row) {
                $storedMatches[$row->getId()] = true;
            }
        }

        $filterAttributes = $this->filterAttributes($filters);
        $documents = $matches = $pending = [];
        foreach ($staged as $id => $docState) {
            $id = (string) $id;
            if (!$docState['exists']) {
                continue;
            }

            $changes = $docState['document'];
            $row = $stored[$id] ?? null;
            if ($row === null && $docState['action'] === 'update') {
                continue;
            }

            if ($row === null || $docState['action'] === 'create') {
                $document = new Document($changes->getArrayCopy());
                $document->setAttribute('$id', $id);
                $pending[$id] = \count($pending);
            } else {
                $document = clone $row;
                foreach ($changes->getAttributes() as $key => $value) {
                    if ($key !== '$id') {
                        $document->setAttribute($key, $value);
                    }
                }
            }

            $touched = \array_intersect(\array_keys($changes->getAttributes()), $filterAttributes) !== [];
            $documents[$id] = $document;
            $matches[$id] = isset($pending[$id]) || $touched
                ? $this->documentMatchesFilters($document, $filters)
                : isset($storedMatches[$id]);
        }

        return new StagedDocuments($documents, $matches, $pending, $storedMatches);
    }

    /**
     * The order a read returns rows in, with the $sequence tie break the database adds.
     *
     * @return list<array{string, OrderDirection}>
     */
    private function orders(ParsedQuery $parsed): array
    {
        $orders = [];
        foreach ($parsed->orderAttributes as $position => $attribute) {
            $direction = $parsed->orderTypes[$position];
            if ($direction !== OrderDirection::Random) {
                $orders[] = [$attribute === '' ? '$sequence' : $attribute, $direction];
            }
        }

        $attributes = \array_column($orders, 0);
        if (!\in_array('$id', $attributes, true) && !\in_array('$sequence', $attributes, true)) {
            $leading = $orders[0] ?? null;
            $orders[] = ['$sequence', $leading !== null && \in_array($leading[0], ['$createdAt', '$updatedAt'], true) ? $leading[1] : OrderDirection::Asc];
        }

        return $orders;
    }

    /**
     * Order attributes a read selects away, selected for the merge and removed from the rows again.
     *
     * @param list<array{string, OrderDirection}> $orders
     * @return list<string>
     */
    private function selectOrderAttributes(ParsedQuery $parsed, array $orders): array
    {
        if ($parsed->selections === []) {
            return [];
        }

        $selected = [];
        foreach ($parsed->selections as $selection) {
            \array_push($selected, ...$selection->getValues());
        }
        if (\in_array('*', $selected, true)) {
            return [];
        }

        $missing = [];
        foreach ($orders as [$attribute]) {
            if (!\str_starts_with($attribute, '$') && !\in_array($attribute, $selected, true)) {
                $missing[] = $attribute;
            }
        }

        return \array_values(\array_unique($missing));
    }

    /**
     * The cursor the stored rows are read past: the cursor row as the staged writes leave it, placed after every stored
     * row when it is staged and not stored yet, as it will be once committed.
     *
     * @param list<array{string, OrderDirection}> $orders
     */
    private function storedCursor(string $collectionId, string $id, Document $document, array $orders, ?int $pending): Document
    {
        $cursor = new Document([
            '$id' => $id,
            '$collection' => $collectionId,
            '$sequence' => $pending === null ? $document->getSequence() : (string) PHP_INT_MAX,
        ]);

        foreach ($orders as [$attribute]) {
            if ($attribute === '$sequence') {
                continue;
            }
            $value = $document->getAttribute($attribute);
            if ($value === null && $pending !== null && \in_array($attribute, ['$createdAt', '$updatedAt'], true)) {
                $value = self::PENDING_TIMESTAMP;
            }
            $cursor->setAttribute($attribute, $value);
        }

        return $cursor;
    }

    /**
     * @param list<array{string, OrderDirection}> $orders
     * @return list<array{array{int, mixed, int}, OrderDirection}>
     */
    private function sortKey(Document $document, array $orders, ?int $pending): array
    {
        $key = [];
        foreach ($orders as [$attribute, $direction]) {
            $value = $attribute === '$sequence' ? null : $document->getAttribute($attribute);
            $key[] = [match (true) {
                $pending !== null && ($attribute === '$sequence' || ($value === null && \in_array($attribute, ['$createdAt', '$updatedAt'], true))) => [2, 0, $pending],
                $attribute === '$sequence' => [1, (int) $document->getSequence(), 0],
                $value === null => [0, 0, 0],
                default => [1, $value, 0],
            }, $direction];
        }

        return $key;
    }

    /**
     * @param list<array{array{int, mixed, int}, OrderDirection}> $a
     * @param list<array{array{int, mixed, int}, OrderDirection}> $b
     */
    private function compareKeys(array $a, array $b): int
    {
        foreach ($a as $position => [$value, $direction]) {
            $comparison = $value <=> $b[$position][0];
            if ($comparison !== 0) {
                return $direction === OrderDirection::Desc ? -$comparison : $comparison;
            }
        }

        return 0;
    }

    /**
     * @param array<Query> $filters
     * @return list<string>
     */
    private function filterAttributes(array $filters): array
    {
        $attributes = [];
        foreach ($filters as $filter) {
            match ($filter->getMethod()) {
                Method::And, Method::Or => \array_push($attributes, ...$this->filterAttributes($filter->getValues())),
                Method::Exists, Method::NotExists => \array_push($attributes, ...\array_map(\strval(...), $filter->getValues())),
                default => $attributes[] = $filter->getAttribute(),
            };
        }

        return $attributes;
    }

    /**
     * Staged changes overlay documents by ID, and an aggregated row has none to overlay.
     *
     * @param array<Query> $queries
     * @throws Exception\Query
     */
    private function assertNotAggregated(array $queries): void
    {
        if (Query::groupByType($queries)->isAggregation()) {
            throw new Exception\Query("Aggregate and groupBy queries cannot read a transaction's uncommitted changes. Run them outside the transaction.");
        }
    }

    /**
     * Apply bulk update to documents in transaction state that match queries
     *
     * This allows bulk operations within a transaction to see each other's changes.
     *
     * @param string $collectionId Collection ID
     * @param Document $updateData Document with update values
     * @param array $queries Query filters to match documents
     * @param array &$state Transaction state (passed by reference)
     * @return void
     */
    public function applyBulkUpdateToState(
        string $collectionId,
        Document $updateData,
        array $queries,
        array &$state
    ): void {
        if (!isset($state[$collectionId])) {
            return;
        }

        $filters = $this->extractFilters($queries);

        foreach ($state[$collectionId] as $docId => $doc) {
            if ($this->documentMatchesFilters($doc, $filters)) {
                foreach ($updateData->getArrayCopy() as $key => $value) {
                    if ($key !== '$id') {
                        $doc->setAttribute($key, $value);
                    }
                }
            }
        }
    }

    /**
     * Apply bulk delete to documents in transaction state that match queries
     *
     * This allows bulk operations within a transaction to see each other's changes.
     *
     * @param string $collectionId Collection ID
     * @param array $queries Query filters to match documents
     * @param array &$state Transaction state (passed by reference)
     * @return void
     */
    public function applyBulkDeleteToState(
        string $collectionId,
        array $queries,
        array &$state
    ): void {
        if (!isset($state[$collectionId])) {
            return;
        }

        $filters = $this->extractFilters($queries);

        foreach ($state[$collectionId] as $docId => $doc) {
            if ($this->documentMatchesFilters($doc, $filters)) {
                unset($state[$collectionId][$docId]);
            }
        }
    }

    /**
     * Apply bulk upsert to documents in transaction state
     *
     * This merges partial upsert data with full documents from transaction state,
     * preventing validation errors when upserting documents created in the same transaction.
     *
     * @param string $collectionId Collection ID
     * @param array $documents Array of Document objects to upsert (can be partial)
     * @param array &$state Transaction state (passed by reference)
     * @return array Merged documents ready for database upsert
     */
    public function applyBulkUpsertToState(
        string $collectionId,
        array $documents,
        array &$state
    ): array {
        $mergedDocuments = [];

        foreach ($documents as $doc) {
            if (!($doc instanceof Document)) {
                continue;
            }

            $docId = $doc->getId();
            if (!$docId) {
                continue;
            }

            if (isset($state[$collectionId][$docId])) {
                foreach ($doc->getArrayCopy() as $key => $value) {
                    if ($key !== '$id') {
                        $state[$collectionId][$docId]->setAttribute($key, $value);
                    }
                }
                $mergedDocuments[] = $state[$collectionId][$docId];
            } else {
                $mergedDocuments[] = $doc;
            }
        }

        return $mergedDocuments;
    }

    /**
     * The staged view of a transaction for a permission pre-check (Database::authorizeCreate() and
     * authorizeUpdate()): given a collection identifier and a document ID, an empty document when the staged writes
     * delete it, the document as they leave it, read without the caller's permissions, or null when they leave it
     * as stored. $pending are operations of the same request, staged before the one being checked and not stored yet.
     *
     * @param array<Document> $pending
     * @return callable(string, string): ?Document
     */
    public function stagedView(Document $database, Document $transaction, array $pending = []): callable
    {
        $state = null;

        return function (string $collection, string $id) use ($database, $transaction, $pending, &$state): ?Document {
            $state ??= $this->replay([
                ...$this->authorization->skip(fn (): array => $this->dbForProject->find('transactionLogs', [
                    Query::equal('transactionInternalId', [$transaction->getSequence()]),
                    Query::orderAsc(),
                    Query::limit(PHP_INT_MAX),
                ])),
                ...$pending,
            ]);

            $staged = $state[$collection][$id] ?? null;
            if ($staged === null) {
                return null;
            }
            if (!$staged['exists']) {
                return new Document();
            }
            if ($staged['action'] === 'create') {
                return new Document($staged['document']->getArrayCopy());
            }

            $stored = $this->authorization->skip(fn (): Document => ($this->getDatabasesDB)($database)->getDocument($collection, $id));
            if ($stored->isEmpty()) {
                return new Document($staged['document']->getArrayCopy());
            }
            foreach ($staged['document']->getAttributes() as $key => $value) {
                if ($key !== '$id') {
                    $stored->setAttribute($key, $value);
                }
            }

            return $stored;
        };
    }

    /**
     * Get the current state of a transaction by replaying its operations
     *
     * @param string $transactionId Transaction ID
     * @return array State array with structure: [collectionId => [docId => ['action' => ..., 'document' => ..., 'exists' => ...]]]
     * @throws Exception
     * @throws Exception\Query
     * @throws Timeout
     */
    private function getTransactionState(string $transactionId): array
    {
        $roles = $this->authorization->getRoles();

        // Staged operations are only visible to the transaction owner, API keys and privileged users.
        $transaction = ($this->user->isKey($roles) || $this->user->isPrivileged($roles))
            ? $this->authorization->skip(fn () => $this->dbForProject->getDocument('transactions', $transactionId))
            : $this->dbForProject->getDocument('transactions', $transactionId);
        if ($transaction->isEmpty() || $transaction->getAttribute('status') !== 'pending') {
            return [];
        }

        $operations = $this->authorization->skip(fn () => $this->dbForProject->find('transactionLogs', [
            Query::equal('transactionInternalId', [$transaction->getSequence()]),
            Query::orderAsc(),
            Query::limit(PHP_INT_MAX)
        ]));

        return $this->replay($operations);
    }

    /**
     * The state staged operations leave, keyed by collection and document ID.
     *
     * @param iterable<Document|array<string, mixed>> $operations
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function replay(iterable $operations): array
    {
        $state = [];

        foreach ($operations as $operation) {
            $databaseInternalId = $operation['databaseInternalId'];
            $collectionInternalId = $operation['collectionInternalId'];
            $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
            $documentId = $operation['documentId'];
            $action = $operation['action'];
            $data = $operation['data'];

            if ($data instanceof Document) {
                $data = $data->getArrayCopy();
            }

            switch ($action) {
                case 'create':
                    $docId = $documentId ?? ($data['$id'] ?? null);
                    if ($docId) {
                        if (!isset($data['$id'])) {
                            $data['$id'] = $docId;
                        }
                        $state[$collectionId][$docId] = [
                            'action' => 'create',
                            'document' => new Document($data),
                            'exists' => true
                        ];
                    }
                    break;

                case 'update':
                    if (isset($state[$collectionId][$documentId])) {
                        $existingDocument = $state[$collectionId][$documentId]['document'];
                        foreach ($data as $key => $value) {
                            if ($key !== '$id') {
                                $existingDocument->setAttribute($key, $value);
                            }
                        }
                        // Only set action to 'update' if it's not already 'create' or 'upsert'
                        $currentAction = $state[$collectionId][$documentId]['action'];
                        if ($currentAction !== 'create' && $currentAction !== 'upsert') {
                            $state[$collectionId][$documentId]['action'] = 'update';
                        }
                    } else {
                        $state[$collectionId][$documentId] = [
                            'action' => 'update',
                            'document' => new Document($data),
                            'exists' => true
                        ];
                    }
                    break;

                case 'upsert':
                    $docId = $documentId ?? ($data['$id'] ?? null);
                    if (!$docId) {
                        break;
                    }
                    $state[$collectionId][$docId] = [
                        'action' => 'upsert',
                        'document' => new Document($data),
                        'exists' => true
                    ];
                    break;

                case 'delete':
                    $state[$collectionId][$documentId] = [
                        'action' => 'delete',
                        'exists' => false
                    ];
                    break;

                case 'increment':
                case 'decrement':
                    $attribute = $data['attribute'] ?? null;
                    $value = $data['value'] ?? 1;

                    if ($attribute) {
                        if (isset($state[$collectionId][$documentId])) {
                            $existingDocument = $state[$collectionId][$documentId]['document'];
                            $currentValue = $existingDocument->getAttribute($attribute, 0);
                            $newValue = $action === 'increment' ? $currentValue + $value : $currentValue - $value;
                            $existingDocument->setAttribute($attribute, $newValue);

                            $currentAction = $state[$collectionId][$documentId]['action'];
                            if ($currentAction !== 'create' && $currentAction !== 'upsert') {
                                $state[$collectionId][$documentId]['action'] = 'update';
                            }
                        } else {
                            $newValue = $action === 'increment' ? $value : -$value;
                            $state[$collectionId][$documentId] = [
                                'action' => 'update',
                                'document' => new Document([$attribute => $newValue]),
                                'exists' => true
                            ];
                        }
                    }
                    break;

                case 'bulkCreate':
                    if (\is_array($data)) {
                        foreach ($data as $doc) {
                            if ($doc instanceof Document) {
                                $doc = $doc->getArrayCopy();
                            }
                            $state[$collectionId][$doc['$id']] = [
                                'action' => 'create',
                                'document' => new Document($doc),
                                'exists' => true
                            ];
                        }
                    }
                    break;

                case 'bulkUpdate':
                    if (isset($data['queries']) && isset($data['data'])) {
                        $queries = Query::parseQueries($data['queries'] ?? []);
                        $updateData = $data['data'];

                        foreach ($state[$collectionId] ?? [] as $docId => $entry) {
                            if (!$entry['exists']) {
                                continue;
                            }

                            $document = $entry['document'];
                            $filters = $this->extractFilters($queries);

                            if ($this->documentMatchesFilters($document, $filters)) {
                                foreach ($updateData as $key => $value) {
                                    if ($key !== '$id') {
                                        $document->setAttribute($key, $value);
                                    }
                                }

                                $currentAction = $state[$collectionId][$docId]['action'];
                                if ($currentAction !== 'create' && $currentAction !== 'upsert') {
                                    $state[$collectionId][$docId]['action'] = 'update';
                                }
                            }
                        }
                    }
                    break;

                case 'bulkUpsert':
                    if (\is_array($data)) {
                        foreach ($data as $doc) {
                            if ($doc instanceof Document) {
                                $doc = $doc->getArrayCopy();
                            }

                            $docId = $doc['$id'] ?? null;
                            if (!$docId) {
                                continue;
                            }

                            if (isset($state[$collectionId][$docId])) {
                                $existingDocument = $state[$collectionId][$docId]['document'];
                                foreach ($doc as $key => $value) {
                                    $existingDocument->setAttribute($key, $value);
                                }
                            } else {
                                $state[$collectionId][$docId] = [
                                    'action' => 'upsert',
                                    'document' => new Document($doc),
                                    'exists' => true
                                ];
                            }
                        }
                    }
                    break;

                case 'bulkDelete':
                    if (isset($data['queries'])) {
                        $queries = Query::parseQueries($data['queries'] ?? []);
                        $filters = $this->extractFilters($queries);

                        foreach ($state[$collectionId] ?? [] as $docId => $entry) {
                            if (!$entry['exists']) {
                                continue;
                            }

                            $document = $entry['document'];
                            if ($this->documentMatchesFilters($document, $filters)) {
                                $state[$collectionId][$docId] = [
                                    'action' => 'delete',
                                    'exists' => false
                                ];
                            }
                        }
                    }
                    break;
            }
        }

        return $state;
    }

    /**
     * Apply projection (select) semantics from queries to a document
     *
     * @param Document $doc Document to apply projection to
     * @param array $queries Query array that may contain select queries
     * @return Document Projected document
     */
    private function applyProjection(Document $doc, array $queries): Document
    {
        if (empty($queries)) {
            return $doc;
        }

        $selections = [];
        $aliases = [];
        $distinct = false;
        foreach ($queries as $query) {
            $method = $query->getMethod();
            if ($method === Method::Distinct) {
                $distinct = true;
            } elseif ($method->isJoin()) {
                $aliases[$query->getAlias()] = true;
            } elseif ($method === Method::Select) {
                $values = $query->getValues();
                foreach ($values as $value) {
                    // Skip relationship selections (containing '.')
                    if (!\str_contains($value, '.')) {
                        $selections[] = $value;
                    }
                }
            }
        }

        if (empty($selections) || \in_array('*', $selections)) {
            return $doc;
        }

        $projected = new Document();

        if (!$distinct) {
            $projected->setAttribute('$id', $doc->getId());
            $projected->setAttribute('$collection', $doc->getCollection());
            $projected->setAttribute('$createdAt', $doc->getCreatedAt());
            $projected->setAttribute('$updatedAt', $doc->getUpdatedAt());
            if ($doc->offsetExists('$permissions')) {
                $projected->setAttribute('$permissions', $doc->getPermissions());
            }
        }

        foreach ($selections as $attribute) {
            if ($doc->offsetExists($attribute)) {
                $projected->setAttribute($attribute, $doc->getAttribute($attribute));
            }
        }

        foreach ($doc as $key => $value) {
            $dot = \strpos((string) $key, '.');
            if ($dot !== false && isset($aliases[\substr((string) $key, 0, $dot)])) {
                $projected->setAttribute((string) $key, $value);
            }
        }

        return $projected;
    }

    /**
     * Extract only filter queries from a query array
     *
     * @param array $queries Query array
     * @return array Filtered queries
     */
    private function extractFilters(array $queries): array
    {
        return Query::groupByType($queries)->filters;
    }

    /**
     * Check if a document matches filter queries. A filter evaluated only by the engine (search, regex, spatial,
     * vector, JSON, elemMatch) does not match a staged value.
     *
     * @param Document $doc Document to check
     * @param array $filters Pre-filtered Query filters (use extractFilters first)
     * @return bool True if document matches all filters
     */
    private function documentMatchesFilters(Document $doc, array $filters): bool
    {
        if (empty($filters)) {
            return true;
        }

        foreach ($filters as $filter) {
            $attribute = $filter->getAttribute();
            $values = $filter->getValues();
            $docValue = $doc->getAttribute($attribute);

            switch ($filter->getMethod()) {
                case Method::And:
                    if (!$this->documentMatchesFilters($doc, $values)) {
                        return false;
                    }
                    break;

                case Method::Or:
                    $matches = false;
                    foreach ($values as $value) {
                        if ($this->documentMatchesFilters($doc, [$value])) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Method::NotContains:
                    foreach ($values as $value) {
                        if ($this->containsValue($docValue, $value)) {
                            return false;
                        }
                    }
                    break;

                case Method::ContainsAll:
                    foreach ($values as $value) {
                        if (!$this->containsValue($docValue, $value)) {
                            return false;
                        }
                    }
                    break;

                case Method::NotStartsWith:
                    if (\is_string($docValue) && \str_starts_with($docValue, (string) $values[0])) {
                        return false;
                    }
                    break;

                case Method::NotEndsWith:
                    if (\is_string($docValue) && \str_ends_with($docValue, (string) $values[0])) {
                        return false;
                    }
                    break;

                case Method::NotBetween:
                    if ($docValue === null || ($docValue >= $values[0] && $docValue <= $values[1])) {
                        return false;
                    }
                    break;

                case Method::Exists:
                    foreach ($values as $value) {
                        if (!$doc->offsetExists((string) $value) || $doc->getAttribute((string) $value) === null) {
                            return false;
                        }
                    }
                    break;

                case Method::NotExists:
                    foreach ($values as $value) {
                        if ($doc->getAttribute((string) $value) !== null) {
                            return false;
                        }
                    }
                    break;

                case Method::Equal:
                    if (!\in_array($docValue, $values)) {
                        return false;
                    }
                    break;

                case Method::NotEqual:
                    if (\in_array($docValue, $values)) {
                        return false;
                    }
                    break;

                case Method::Contains:
                case Method::ContainsAny:
                    $matches = false;
                    foreach ($values as $value) {
                        if ($this->containsValue($docValue, $value)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Method::StartsWith:
                    $matches = false;
                    foreach ($values as $value) {
                        if (\is_string($docValue) && \str_starts_with($docValue, $value)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Method::EndsWith:
                    $matches = false;
                    foreach ($values as $value) {
                        if (\is_string($docValue) && \str_ends_with($docValue, $value)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                    break;

                case Method::GreaterThan:
                    if ($docValue === null || !($docValue > $values[0])) {
                        return false;
                    }
                    break;

                case Method::GreaterThanEqual:
                    if ($docValue === null || !($docValue >= $values[0])) {
                        return false;
                    }
                    break;

                case Method::LessThan:
                    if ($docValue === null || !($docValue < $values[0])) {
                        return false;
                    }
                    break;

                case Method::LessThanEqual:
                    if ($docValue === null || !($docValue <= $values[0])) {
                        return false;
                    }
                    break;

                case Method::IsNull:
                    if (!\is_null($docValue)) {
                        return false;
                    }
                    break;

                case Method::IsNotNull:
                    if (\is_null($docValue)) {
                        return false;
                    }
                    break;

                case Method::Between:
                    if ($docValue === null || !($docValue >= $values[0] && $docValue <= $values[1])) {
                        return false;
                    }
                    break;

                default:
                    return false;
            }
        }

        return true;
    }

    private function containsValue(mixed $haystack, mixed $needle): bool
    {
        if (\is_array($haystack)) {
            return \in_array($needle, $haystack);
        }

        return \is_string($haystack) && \is_string($needle) && \str_contains($haystack, $needle);
    }
}
