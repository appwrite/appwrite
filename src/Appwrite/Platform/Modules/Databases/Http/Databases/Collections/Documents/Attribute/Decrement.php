<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Attribute;

use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use InvalidArgumentException;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Nullable;
use Utopia\Validator\Numeric;

class Decrement extends Action
{
    public static function getName(): string
    {
        return 'decrementDocumentAttribute';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId/:attribute/decrement')
            ->desc('Decrement document attribute')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'documents.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/decrement-document-attribute.md',
                auth: [AuthType::SESSION, AuthType::JWT, AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.decrementRowColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('documentId', '', new UID(), 'Document ID.')
            ->param('attribute', '', new Key(), 'Attribute key.')
            ->param('value', 1, new Numeric(), 'Value to increment the attribute by. The value must be a number.', true)
            ->param('min', null, new Nullable(new Numeric()), 'Minimum value for the attribute. If the current value is lesser than this value, an exception will be thrown.', true)
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $documentId, string $attribute, int|float $value, int|float|null $min, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage, array $plan, Authorization $authorization): void
    {
        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        // Handle transaction staging
        if ($transactionId !== null) {
            $transaction = ($isAPIKey || $isPrivilegedUser)
                ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
                : $dbForProject->getDocument('transactions', $transactionId);
            if ($transaction->isEmpty() || $transaction->getAttribute('status', '') !== 'pending') {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid or non‑pending transaction');
            }

            $now = new \DateTime();
            $expiresAt = new \DateTime($transaction->getAttribute('expiresAt', 'now'));
            if ($now > $expiresAt) {
                throw new Exception(Exception::TRANSACTION_EXPIRED);
            }

            // Enforce max operations per transaction
            $maxBatch = $plan['databasesTransactionSize'] ?? APP_LIMIT_DATABASE_TRANSACTION;
            $existing = $transaction->getAttribute('operations', 0);
            if (($existing + 1) > $maxBatch) {
                throw new Exception(
                    Exception::TRANSACTION_LIMIT_EXCEEDED,
                    'Transaction already has ' . $existing . ' operations, adding 1 would exceed the maximum of ' . $maxBatch
                );
            }

            // Stage the operation in transaction logs
            $staged = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => $documentId,
                'action' => 'decrement',
                'data' => [
                    $this->getAttributeKey() => $attribute,
                    'value' => $value,
                    'min' => $min,
                ],
            ]);

            $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged) {
                $dbForProject->createDocument('transactionLogs', $staged);
                $dbForProject->increaseDocumentAttribute(
                    'transactions',
                    $transactionId,
                    'operations',
                    1
                );
            });

            $queueForEvents->reset();

            // Return successful response without actually decrementing
            $groupId = $this->getGroupId();
            $mockDocument = new Document([
                '$id' => $documentId,
                '$' . $groupId => $collectionId,
                '$databaseId' => $databaseId,
                $attribute => $value,
            ]);
            $response
                ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
                ->dynamic($mockDocument, $this->getResponseModel());
            return;
        }

        try {
            $document = $dbForProject->decreaseDocumentAttribute(
                collection: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                id: $documentId,
                attribute: $attribute,
                value: $value,
                min: $min
            );
            $document->setAttribute('$' . $this->getCollectionsEventsContext() . 'Id', $collectionId);
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (NotFoundException) {
            throw new Exception($this->getStructureNotFoundException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException(), $this->getSDKNamespace() . ' "' . $attribute . '" has reached the minimum value of ' . $min);
        } catch (TypeException) {
            throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, $this->getSDKNamespace() . ' "' . $attribute . '" is not a number');
        } catch (InvalidArgumentException $e) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $e->getMessage());
        }

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collectionId)
            ->setParam('tableId', $collectionId)
            ->setParam('documentId', $documentId)
            ->setParam('rowId', $documentId)
            ->setContext('database', $database)
            ->setContext($this->getCollectionsEventsContext(), $collection)
            ->setPayload($response->getPayload(), sensitive: $relationships);

        $response->dynamic($document, $this->getResponseModel());
    }
}
