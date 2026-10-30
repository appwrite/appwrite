<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions;

use Appwrite\Databases\ListCache;
use Appwrite\Databases\TransactionState;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Query\Schema\ColumnType;
use Utopia\Validator\Boolean;

class Update extends Action
{
    private const int STATUS_ATTEMPTS = 3;

    private const int STATUS_BACKOFF_MICROSECONDS = 100_000;

    public static function getName(): string
    {
        return 'updateDatabasesTransaction';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/transactions/:transactionId')
            ->desc('Update transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'updateTransaction',
                description: '/docs/references/databases/update-transaction.md',
                requestExamples: [
                    'commit' => ['summary' => 'Commit the transaction', 'value' => ['commit' => true]],
                    'rollback' => ['summary' => 'Roll back the transaction', 'value' => ['rollback' => true]],
                ],
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.updateTransaction',
                )
            ))
            ->param('transactionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Transaction ID.', false, ['dbForProject'])
            ->param('commit', false, new Boolean(), 'Commit transaction?', true)
            ->param('rollback', false, new Boolean(), 'Rollback transaction?', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('user')
            ->inject('transactionState')
            ->inject('publisherForDeletes')
            ->inject('queueForEvents')
            ->inject('usage')
            ->inject('queueForRealtime')
            ->inject('publisherForFunctions')
            ->inject('queueForWebhooks')
            ->inject('authorization')
            ->inject('eventProcessor')
            ->callback($this->action(...));
    }

    /**
     * @param string $transactionId
     * @param bool $commit
     * @param bool $rollback
     * @param UtopiaResponse $response
     * @param Database $dbForProject
     * @param callable $getDatabasesDB
     * @param User $user
     * @param TransactionState $transactionState
     * @param DeletePublisher $publisherForDeletes
     * @param Event $queueForEvents
     * @param Context $usage
     * @param Event $queueForRealtime
     * @param FunctionPublisher $publisherForFunctions
     * @param Event $queueForWebhooks
     * @param EventProcessor $eventProcessor
     * @return void
     * @throws ConflictException
     * @throws Exception
     * @throws \Throwable
     * @throws \Utopia\Database\Exception
     * @throws StructureException
     * @throws \Utopia\Http\Exception
     */
    public function action(string $transactionId, bool $commit, bool $rollback, Document $project, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, User $user, TransactionState $transactionState, DeletePublisher $publisherForDeletes, Event $queueForEvents, Context $usage, Event $queueForRealtime, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, Authorization $authorization, EventProcessor $eventProcessor): void
    {
        if (!$commit && !$rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Either commit or rollback must be true');
        }
        if ($commit && $rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Cannot commit and rollback at the same time');
        }

        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $transaction = ($isAPIKey || $isPrivilegedUser)
            ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
            : $dbForProject->getDocument('transactions', $transactionId);
        if ($transaction->isEmpty() || $this->isTransactionTypeMismatch($transaction)) {
            throw new Exception(Exception::TRANSACTION_NOT_FOUND, params: [$transactionId]);
        }
        if ($transaction->getAttribute('status', '') !== 'pending') {
            throw new Exception(Exception::TRANSACTION_NOT_READY);
        }

        $now = new \DateTime();
        $expiresAt = new \DateTime($transaction->getAttribute('expiresAt', 'now'));
        if ($now > $expiresAt) {
            throw new Exception(Exception::TRANSACTION_EXPIRED);
        }

        if ($commit) {
            $operations = [];
            $totalOperations = 0;
            $databaseOperations = [];
            $bulkDocuments = [];
            // Set by reference inside the commit closure, so the catch blocks below can read them
            /** @var ?string $currentDocumentId */
            $currentDocumentId = null;
            /** @var ?string $currentAttribute */
            $currentAttribute = null;
            /** @var int|float|null $currentChange */
            $currentChange = null;

            $claim = function () use ($dbForProject, $transactionId) {
                // Re-read under a lock, a concurrent commit of the same transaction must not apply its operations twice,
                // and staging, which takes the same lock, must not add operations after they are read below
                $current = $dbForProject->getDocument('transactions', $transactionId, forUpdate: true);

                if ($current->getAttribute('status', '') !== 'pending') {
                    return new Document();
                }

                return $dbForProject->updateDocument('transactions', $transactionId, new Document(['status' => 'committing']));
            };

            $transaction = ($isAPIKey || $isPrivilegedUser)
                ? $authorization->skip(fn () => $dbForProject->withTransaction($claim))
                : $dbForProject->withTransaction($claim);

            if ($transaction->isEmpty()) {
                throw new Exception(Exception::TRANSACTION_NOT_READY);
            }

            // The transaction is claimed, so any failure from here on must mark it failed rather than leave it committing
            try {
                $firstOperation = $authorization->skip(fn () => $dbForProject->findOne('transactionLogs', [
                    Query::equal('transactionInternalId', [$transaction->getSequence()]),
                    Query::orderAsc(),
                ]));

                if ($firstOperation->isEmpty()) {
                    $transaction = $authorization->skip(fn () => $dbForProject->updateDocument(
                        'transactions',
                        $transactionId,
                        new Document(['status' => 'committed'])
                    ));

                    $this->queueCleanup($publisherForDeletes, $project, $transaction);

                    $response
                        ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
                        ->dynamic($transaction, $this->getResponseModel());

                    return;
                }

                $databaseDoc = $authorization->skip(fn () => $dbForProject->findOne('databases', [
                    Query::equal('$sequence', [$firstOperation['databaseInternalId']])
                ]));
                $databaseDsn = $databaseDoc->getAttribute('database') ?: $project->getAttribute('database');

                $dbForDatabases = $getDatabasesDB($databaseDoc);

                $operations = $authorization->skip(fn () => $dbForProject->find('transactionLogs', [
                    Query::equal('transactionInternalId', [$transaction->getSequence()]),
                    Query::orderAsc(),
                    Query::limit(PHP_INT_MAX),
                ]));

                $databaseDsns = [$firstOperation['databaseInternalId'] => $databaseDsn];
                $collections = [];
                foreach ($operations as $operation) {
                    $databaseInternalId = $operation['databaseInternalId'];
                    $collectionInternalId = $operation['collectionInternalId'];
                    $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";

                    if (!isset($databaseDsns[$databaseInternalId])) {
                        $operationDatabase = $authorization->skip(fn () => $dbForProject->findOne('databases', [
                            Query::equal('$sequence', [$databaseInternalId])
                        ]));
                        $databaseDsns[$databaseInternalId] = $operationDatabase->getAttribute('database') ?: $project->getAttribute('database');
                    }

                    if ($databaseDsns[$databaseInternalId] !== $databaseDsn) {
                        throw new Exception(Exception::TRANSACTION_INVALID, 'All operations in a transaction must target databases hosted on the same database instance.');
                    }

                    if (!isset($collections[$collectionId])) {
                        $collections[$collectionId] = $authorization->skip(
                            fn () => $dbForDatabases->getCollection($collectionId)
                        );
                    }
                }

                $commit = function () use ($dbForDatabases, $transactionState, &$operations, &$totalOperations, &$databaseOperations, &$bulkDocuments, &$currentDocumentId, &$currentAttribute, &$currentChange, $collections) {
                    $state = [];

                    foreach ($operations as $operation) {
                        $databaseInternalId = $operation['databaseInternalId'];
                        $collectionInternalId = $operation['collectionInternalId'];
                        $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
                        $documentId = $operation['documentId'];
                        $currentDocumentId = $documentId;
                        $currentAttribute = null;
                        $currentChange = null;
                        $createdAt = new \DateTime($operation['$createdAt']);
                        $action = $operation['action'];
                        $data = $operation['data'];

                        if ($data instanceof Document) {
                            $data = $data->getArrayCopy();
                        }

                        $collection = $collections[$collectionId];

                        if (\in_array($action, ['increment', 'decrement'], true) && \is_array($data)) {
                            $currentAttribute = TransactionState::attributeOf($data, $this->getAttributeKey());
                            $currentChange = $data['value'] ?? 1;
                        }

                        if (\is_array($data) && !empty($data)) {
                            $data = $this->parseStagedOperators($action, $data, $collection);
                        }

                        if ($action === 'delete' && $documentId) {
                            $doc = $dbForDatabases->getDocument($collectionId, $documentId);
                            $operation['data'] = $doc->isEmpty() ? [] : $doc->getArrayCopy();
                            $data = $operation['data'];
                        }

                        if (!\in_array($action, ['bulkCreate', 'bulkUpdate', 'bulkUpsert', 'bulkDelete'])) {
                            $totalOperations++;
                            $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + 1;
                        }

                        $bulkDocuments[$operation->getId()] = [];

                        switch ($action) {
                            case 'create':
                                $this->handleCreateOperation($dbForDatabases, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'update':
                                $this->handleUpdateOperation($dbForDatabases, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'upsert':
                                $this->handleUpsertOperation($dbForDatabases, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'delete':
                                $this->handleDeleteOperation($dbForDatabases, $collectionId, $documentId, $createdAt, $state);
                                break;
                            case 'increment':
                                $this->handleIncrementOperation($dbForDatabases, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'decrement':
                                $this->handleDecrementOperation($dbForDatabases, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'bulkCreate':
                                $count = $this->handleBulkCreateOperation($dbForDatabases, $collectionId, $data, $createdAt, $state, $bulkDocuments[$operation->getId()]);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                            case 'bulkUpdate':
                                $count = $this->handleBulkUpdateOperation($dbForDatabases, $collectionId, $data, $createdAt, $state, $bulkDocuments[$operation->getId()]);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                            case 'bulkUpsert':
                                $count = $this->handleBulkUpsertOperation($dbForDatabases, $transactionState, $collectionId, $data, $createdAt, $state, $bulkDocuments[$operation->getId()]);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                            case 'bulkDelete':
                                $count = $this->handleBulkDeleteOperation($dbForDatabases, $collectionId, $data, $createdAt, $state, $bulkDocuments[$operation->getId()]);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                        }
                    }

                };

                // Every write purges its cached rows, nested and cascaded rows included, inside a savepoint of
                // this transaction, so a read before COMMIT can cache the old row again. Purge them again after COMMIT.
                $purged = ListCache::purged(
                    $this->getPermissionListCaches($dbForProject, $authorization, $operations),
                    fn () => $dbForDatabases->getCache()->recordPurges(fn () => $dbForDatabases->withTransaction($commit)),
                );
            } catch (NotFoundException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));

                throw new Exception($this->getNotFoundException(), previous: $e, params: [$currentDocumentId ?? 'unknown']);
            } catch (DuplicateException | ConflictException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::TRANSACTION_CONFLICT, previous: $e);
            } catch (RelationshipException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
            } catch (StructureException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
            } catch (LimitException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, $e->getMessage());
            } catch (TypeException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                if ($currentAttribute === null) {
                    throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, $e->getMessage());
                }
                if ($currentChange <= 0) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $e->getMessage());
                }
                throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, \ucfirst($this->getAttributeKey()) . ' "' . $currentAttribute . '" is not a number');
            } catch (TransactionException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::TRANSACTION_FAILED, $e->getMessage());
            } catch (QueryException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            } catch (AuthorizationException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::USER_UNAUTHORIZED, previous: $e);
            } catch (\Throwable $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw $e;
            }

            // The writes are committed from here on: nothing below may mark the transaction failed or fail the request.
            foreach ($purged as $key => $hashes) {
                foreach (\array_keys($hashes) as $hash) {
                    try {
                        $dbForDatabases->getCache()->purge($key, $hash);
                    } catch (\Throwable $e) {
                        Console::warning('Failed to purge committed document from cache: ' . $e->getMessage());
                    }
                }
            }

            $transaction = $this->storeCommitted($dbForProject, $authorization, $transaction);

            $this->queueCleanup($publisherForDeletes, $project, $transaction);

            foreach ($databaseOperations as $databaseInternalId => $count) {
                $database = $authorization->skip(fn () => $dbForProject->skipFilters(
                    fn () => $dbForProject->findOne('databases', [
                        Query::equal('$sequence', [$databaseInternalId])
                    ]),
                    APP_DATABASES_SUBQUERIES
                ));

                $usage
                    ->setResource('database')
                    ->setResourceId($database->getId())
                    ->setResourceInternalId((string) $databaseInternalId)
                    ->addMetric($this->getDatabasesOperationWriteMetric(), $count);
            }

            // find() is not served from the document cache.
            $dbCache = [];
            $databaseCache = [];
            $collectionCache = [];
            foreach ($operations as $operation) {
                $databaseInternalId = $operation['databaseInternalId'];
                $collectionInternalId = $operation['collectionInternalId'];
                $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
                $action = $operation['action'];
                $documentId = $operation['documentId'];
                $data = $operation['data'];

                if ($data instanceof Document) {
                    $data = $data->getArrayCopy();
                }

                $database = $databaseCache[$databaseInternalId] ??= $authorization->skip(fn () => $dbForProject->skipFilters(
                    fn () => $dbForProject->findOne('databases', [
                        Query::equal('$sequence', [$databaseInternalId])
                    ]),
                    APP_DATABASES_SUBQUERIES
                ));
                $dbForDatabases = $dbCache[$databaseInternalId] ??= $getDatabasesDB($database);

                $collection = $collectionCache[$collectionId] ??= $authorization->skip(fn () => $dbForProject->skipFilters(
                    fn () => $dbForProject->findOne('database_' . $databaseInternalId, [
                        Query::equal('$sequence', [$collectionInternalId])
                    ]),
                    APP_COLLECTIONS_SUBQUERIES
                ));

                $groupId = $this->getGroupId();
                $resourceId = $this->getResourceId();
                $contextKey = $this->getContext();
                $resource = $this->getResource();
                $resourcePlural = $resource . 's';

                $queueForEvents
                    ->setParam('databaseId', $database->getId())
                    ->setContext('database', $database)
                    ->setParam('collectionId', $collection->getId())
                    ->setParam('tableId', $collection->getId())
                    ->setContext($contextKey, $collection);

                $eventAction = '';
                $documentsToTrigger = [];

                switch ($action) {
                    case 'create':
                        $eventAction = 'create';
                        $docId = $documentId ?? $data['$id'] ?? null;
                        if ($docId) {
                            $doc = $dbForDatabases->getDocument($collectionId, $docId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'update':
                    case 'increment':
                    case 'decrement':
                        $eventAction = 'update';
                        if ($documentId) {
                            $doc = $dbForDatabases->getDocument($collectionId, $documentId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'delete':
                        $eventAction = 'delete';
                        if ($documentId && !empty($data)) {
                            $documentsToTrigger[] = new Document(array_merge($data, ['$id' => $documentId]));
                        }
                        break;
                    case 'upsert':
                        $eventAction = 'update';
                        $docId = $documentId ?? $data['$id'] ?? null;
                        if ($docId) {
                            $doc = $dbForDatabases->getDocument($collectionId, $docId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'bulkCreate':
                        $eventAction = 'create';
                        $documentsToTrigger = $bulkDocuments[$operation->getId()];
                        break;
                    case 'bulkUpdate':
                        $eventAction = 'update';
                        $documentsToTrigger = $bulkDocuments[$operation->getId()];
                        break;
                    case 'bulkUpsert':
                        $eventAction = 'upsert';
                        $documentsToTrigger = $bulkDocuments[$operation->getId()];
                        break;
                    case 'bulkDelete':
                        $eventAction = 'delete';
                        $documentsToTrigger = $bulkDocuments[$operation->getId()];
                        break;
                }

                $eventString = "databases.[databaseId].{$contextKey}s.[{$groupId}].{$resourcePlural}.[{$resourceId}]." . $eventAction;

                $queueForEvents->setEvent($eventString);

                // The metadata findOne above skips attribute subqueries. Use the
                // collection loaded via getCollection() during commit, which
                // already has the schema needed to match update event payloads.
                $schema = $collections[$collectionId] ?? $collection;
                $relationships = [];
                foreach ($schema->getAttribute('attributes', []) as $attribute) {
                    if (($attribute['type'] ?? null) === ColumnType::Relationship->value) {
                        $relationships[] = $attribute['key'];
                    }
                }

                // Get project and function/webhook events (cached)
                $project = $queueForEvents->getProject();
                $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
                $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

                foreach ($documentsToTrigger as $doc) {
                    $doc->removeAttribute('$collection');
                    $doc->removeAttribute('$tenant');

                    // Match the key set processDocument() gives every other row and document
                    // event: the synthetic $databaseId, plus whichever of $tableId or
                    // $collectionId belongs to the surface that was called.
                    $payload = $doc->getArrayCopy();
                    $payload['$databaseId'] = $database->getId();
                    $payload['$' . $groupId] = $collection->getId();

                    $queueForEvents
                        ->setParam('documentId', $doc->getId())
                        ->setParam('rowId', $doc->getId())
                        ->setPayload($payload, sensitive: $relationships);

                    // Generate events for this document operation
                    $generatedEvents = Event::generateEvents(
                        $queueForEvents->getEvent(),
                        $queueForEvents->getParams(),
                        $queueForEvents->getContext('database')
                    );

                    $queueForRealtime->from($queueForEvents)->trigger();

                    // Only trigger functions if there are matching function events
                    if (!empty($functionsEvents)) {
                        foreach ($generatedEvents as $event) {
                            if (isset($functionsEvents[$event])) {
                                $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                                    event: $queueForEvents->getEvent(),
                                    params: $queueForEvents->getParams(),
                                    project: $queueForEvents->getProject(),
                                    user: $queueForEvents->getUser(),
                                    userId: $queueForEvents->getUserId(),
                                    payload: $queueForEvents->getPayload(),
                                    platform: $queueForEvents->getPlatform(),
                                    database: $queueForEvents->getContext('database'),
                                ));
                                break;
                            }
                        }
                    }

                    // Only trigger webhooks if there are matching webhook events
                    if (!empty($webhooksEvents)) {
                        foreach ($generatedEvents as $event) {
                            if (isset($webhooksEvents[$event])) {
                                $queueForWebhooks->from($queueForEvents)->trigger();
                                break;
                            }
                        }
                    }
                }

                $queueForEvents->reset();
                $queueForRealtime->reset();
                $queueForWebhooks->reset();
            }
        }

        if ($rollback) {
            $transaction = $authorization->skip(fn () => $dbForProject->updateDocument(
                'transactions',
                $transactionId,
                new Document(['status' => 'failed'])
            ));

            $this->queueCleanup($publisherForDeletes, $project, $transaction);
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }

    /**
     * The cached list responses of each collection with document security that an operation sets document
     * permissions in.
     *
     * @param array<Document> $operations
     * @return array<string, ListCache>
     */
    private function getPermissionListCaches(Database $dbForProject, Authorization $authorization, array $operations): array
    {
        $caches = [];

        foreach ($operations as $operation) {
            $data = $operation['data'] instanceof Document ? $operation['data']->getArrayCopy() : $operation['data'];
            $key = $operation['databaseInternalId'] . ':' . $operation['collectionInternalId'];

            if (isset($caches[$key]) || !$this->setsPermissions($operation['action'], $data)) {
                continue;
            }

            $database = $authorization->skip(fn () => $dbForProject->skipFilters(
                fn () => $dbForProject->findOne('databases', [
                    Query::equal('$sequence', [$operation['databaseInternalId']])
                ]),
                APP_DATABASES_SUBQUERIES
            ));

            $collection = $authorization->skip(fn () => $dbForProject->skipFilters(
                fn () => $dbForProject->findOne('database_' . $operation['databaseInternalId'], [
                    Query::equal('$sequence', [$operation['collectionInternalId']])
                ]),
                APP_COLLECTIONS_SUBQUERIES
            ));

            if ($collection->getAttribute('documentSecurity', false)) {
                $caches[$key] = $this->getListCache($dbForProject, $database, $collection->getId());
            }
        }

        return $caches;
    }

    private function setsPermissions(string $action, mixed $data): bool
    {
        return match ($action) {
            'update', 'upsert' => isset($data['$permissions']),
            'bulkUpdate' => isset($data['data']['$permissions']),
            'bulkUpsert' => \array_any($data, fn (mixed $document): bool => isset($document['$permissions'])),
            default => false,
        };
    }

    private function queueCleanup(DeletePublisher $publisherForDeletes, Document $project, Document $transaction): void
    {
        try {
            if ($publisherForDeletes->enqueue(new DeleteMessage(project: $project, type: DELETE_TYPE_DOCUMENT, document: $transaction)) !== false) {
                return;
            }
            $reason = 'the broker refused it';
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
        }

        Console::warning("Failed to queue the deletion of transaction {$transaction->getId()}, left for the expired transaction cleanup: {$reason}");
    }

    private function storeCommitted(Database $dbForProject, Authorization $authorization, Document $transaction): Document
    {
        $committed = (clone $transaction)->setAttribute('status', 'committed');

        for ($attempt = 1; ; $attempt++) {
            try {
                $stored = $authorization->skip(fn (): Document => $dbForProject->updateDocument(
                    'transactions',
                    $transaction->getId(),
                    new Document(['status' => 'committed']),
                ));
            } catch (\Throwable $error) {
                if ($attempt >= self::STATUS_ATTEMPTS) {
                    Console::error("Transaction {$transaction->getId()} committed, but its status could not be stored: {$error->getMessage()}");

                    return $committed;
                }

                \usleep(self::STATUS_BACKOFF_MICROSECONDS * $attempt);

                continue;
            }

            if ($stored->isEmpty()) {
                Console::warning("Transaction {$transaction->getId()} committed, but it was deleted before its status could be stored");

                return $committed;
            }

            return $stored;
        }
    }

    /**
     * Handle create operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param string|null $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     */
    private function handleCreateOperation(
        Database $dbForDatabases,
        string $collectionId,
        ?string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        if ($documentId) {
            $data['$id'] = $documentId;
        }
        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $data, &$state) {
            $doc = $dbForDatabases->createDocument(
                $collectionId,
                $this->stripResponseMetadata(new Document($data)),
            );
            $state[$collectionId][$doc->getId()] = $doc;
        });
    }

    /**
     * Handle update operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param string $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleUpdateOperation(
        Database $dbForDatabases,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            $state[$collectionId][$documentId] = $dbForDatabases->updateDocument(
                $collectionId,
                $documentId,
                $this->stripResponseMetadata(new Document($data)),
            );
            return;
        }

        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $documentId, $data, &$state) {
            $document = $dbForDatabases->updateDocument(
                $collectionId,
                $documentId,
                $this->stripResponseMetadata(new Document($data)),
            );
            if ($document->isEmpty()) {
                throw new NotFoundException('');
            }
            $state[$collectionId][$documentId] = $document;
        });
    }

    /**
     * Handle upsert operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param string|null $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     */
    private function handleUpsertOperation(
        Database $dbForDatabases,
        string $collectionId,
        ?string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        if ($documentId) {
            $data['$id'] = $documentId;
        }

        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            $existingDoc = $state[$collectionId][$documentId];
            foreach ($data as $key => $value) {
                if ($key !== '$id') {
                    $existingDoc->setAttribute($key, $value);
                }
            }

            $state[$collectionId][$documentId] = $dbForDatabases->upsertDocument(
                $collectionId,
                $this->stripResponseMetadata($existingDoc),
            );
            return;
        }

        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $data, &$state) {
            $doc = $dbForDatabases->upsertDocument(
                $collectionId,
                $this->stripResponseMetadata(new Document($data)),
            );
            $state[$collectionId][$doc->getId()] = $doc;
        });
    }

    private function stripResponseMetadata(Document $document): Document
    {
        foreach (['$databaseId', '$collectionId', '$tableId', '$sequence'] as $attribute) {
            unset($document[$attribute]);
        }

        return $document;
    }

    /**
     * Handle delete operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param string $documentId
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws NotFoundException
     */
    private function handleDeleteOperation(
        Database $dbForDatabases,
        string $collectionId,
        string $documentId,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            $dbForDatabases->deleteDocument($collectionId, $documentId);
            unset($state[$collectionId][$documentId]);
            return;
        }

        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $documentId, &$state) {
            $deleted = $dbForDatabases->deleteDocument($collectionId, $documentId);
            if (!$deleted) {
                throw new NotFoundException('');
            }
            if (isset($state[$collectionId][$documentId])) {
                unset($state[$collectionId][$documentId]);
            }
        });
    }

    /**
     * Handle increment operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param string $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleIncrementOperation(
        Database $dbForDatabases,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);
        $attribute = TransactionState::attributeOf($data, $this->getAttributeKey());

        if ($dependent) {
            $state[$collectionId][$documentId] = $dbForDatabases->increaseDocumentAttribute($collectionId, $documentId, $attribute, $data['value'] ?? 1, $data['max'] ?? null);
            return;
        }

        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $documentId, $data, &$state, $attribute) {
            $state[$collectionId][$documentId] = $dbForDatabases->increaseDocumentAttribute($collectionId, $documentId, $attribute, $data['value'] ?? 1, $data['max'] ?? null);
        });
    }

    /**
     * Handle decrement operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param string $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleDecrementOperation(
        Database $dbForDatabases,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);
        $attribute = TransactionState::attributeOf($data, $this->getAttributeKey());

        if ($dependent) {
            $state[$collectionId][$documentId] = $dbForDatabases->decreaseDocumentAttribute($collectionId, $documentId, $attribute, $data['value'] ?? 1, $data['min'] ?? null);
            return;
        }

        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $documentId, $data, &$state, $attribute) {
            $state[$collectionId][$documentId] = $dbForDatabases->decreaseDocumentAttribute($collectionId, $documentId, $attribute, $data['value'] ?? 1, $data['min'] ?? null);
        });
    }

    /**
     * Handle bulk create operation
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @param array<Document> &$affected
     * @return int Number of documents created
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkCreateOperation(
        Database $dbForDatabases,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state,
        array &$affected
    ): int {
        $count = 0;
        $dbForDatabases->withRequestTimestamp($createdAt, function () use ($dbForDatabases, $collectionId, $data, &$state, &$affected, &$count) {
            $documents = \array_map(function ($doc) {
                return $doc instanceof Document ? $doc : new Document($doc);
            }, $data);

            $count = $dbForDatabases->createDocuments(
                $collectionId,
                $documents,
                onNext: function (Document $document) use (&$state, &$affected, $collectionId) {
                    $state[$collectionId][$document->getId()] = $document;
                    // Later operations change state documents in place, so the event keeps a copy
                    $affected[] = clone $document;
                }
            );
        });
        return $count;
    }

    /**
     * Handle bulk update operation with manual timestamp checking
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @param array<string, Document> &$affected
     * @return int Number of documents updated
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     * @throws ConflictException
     */
    private function handleBulkUpdateOperation(
        Database $dbForDatabases,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state,
        array &$affected
    ): int {
        $queries = Query::parseQueries($data['queries'] ?? []);

        // The database matches rows and applies operators, so its result is the new state
        return $dbForDatabases->updateDocuments(
            $collectionId,
            new Document($data['data']),
            $queries,
            onNext: function (Document $updated, Document $old) use (&$state, &$affected, $collectionId, $createdAt) {
                $dependent = isset($state[$collectionId][$updated->getId()]);

                if (!$dependent) {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                    if ($oldUpdatedAt > $createdAt) {
                        throw new ConflictException('Document was updated after the request timestamp');
                    }
                }

                $state[$collectionId][$updated->getId()] = $updated;
                $affected[$updated->getId()] = clone $updated;
            }
        );
    }

    /**
     * Handle bulk upsert operation with manual timestamp checking
     *
     * @param Database $dbForDatabases
     * @param TransactionState $transactionState
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @param array<Document> &$affected
     * @return int Number of documents upserted
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkUpsertOperation(
        Database $dbForDatabases,
        TransactionState $transactionState,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state,
        array &$affected
    ): int {
        $documents = \array_map(function ($doc) {
            $document = $doc instanceof Document ? $doc : new Document($doc);

            return $this->stripResponseMetadata($document);
        }, $data);

        $mergedDocuments = \array_map(
            $this->stripResponseMetadata(...),
            $transactionState->applyBulkUpsertToState($collectionId, $documents, $state),
        );

        $count = $dbForDatabases->upsertDocuments(
            $collectionId,
            $mergedDocuments,
            onNext: function (Document $upserted, ?Document $old) use (&$state, &$affected, $collectionId, $createdAt) {
                if ($old !== null) {
                    $dependent = isset($state[$collectionId][$upserted->getId()]);

                    if (!$dependent) {
                        $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                        if ($oldUpdatedAt > $createdAt) {
                            throw new ConflictException('Document was updated after the request timestamp');
                        }
                    }
                }

                $state[$collectionId][$upserted->getId()] = $upserted;
                $affected[] = clone $upserted;
            }
        );

        return $count;
    }

    /**
     * Handle bulk delete operation with manual timestamp checking
     *
     * @param Database $dbForDatabases
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @param array<Document> &$affected
     * @return int Number of documents deleted
     * @throws \Utopia\Database\Exception\Query
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkDeleteOperation(
        Database $dbForDatabases,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state,
        array &$affected
    ): int {
        $queries = Query::parseQueries($data['queries'] ?? []);

        return $dbForDatabases->deleteDocuments(
            $collectionId,
            $queries,
            onNext: function (Document $deleted, Document $old) use (&$state, &$affected, $collectionId, $createdAt) {
                $dependent = isset($state[$collectionId][$deleted->getId()]);

                if (!$dependent) {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                    if ($oldUpdatedAt > $createdAt) {
                        throw new ConflictException('Document was updated after the transaction operation');
                    }
                }

                if (isset($state[$collectionId][$deleted->getId()])) {
                    unset($state[$collectionId][$deleted->getId()]);
                }

                $affected[] = $deleted;
            }
        );
    }
}
