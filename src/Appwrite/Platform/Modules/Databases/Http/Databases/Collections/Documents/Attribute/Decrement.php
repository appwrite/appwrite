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
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
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
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].decrement')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'documents.decrement')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/decrement-document-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'grids.decrementRowColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('documentId', '', new UID(), 'Document ID.')
            ->param('attribute', '', new Key(), 'Attribute key.')
            ->param('value', 1, new Numeric(), 'Value to increment the attribute by. The value must be a number.', true)
            ->param('min', null, new Numeric(), 'Minimum value for the attribute. If the current value is lesser than this value, an exception will be thrown.', true)
            ->param('transactionId', null, new UID(), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $documentId, string $attribute, int|float $value, int|float|null $min, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException());
        }

        try {
            $document = $dbForProject->decreaseDocumentAttribute(
                collection: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                id: $documentId,
                attribute: $attribute,
                value: $value,
                min: $min
            );
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (NotFoundException) {
            throw new Exception($this->getStructureNotFoundException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException(), $this->getSdkNamespace() . ' "' . $attribute . '" has reached the minimum value of ' . $min);
        } catch (TypeException) {
            throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, $this->getSdkNamespace() . ' "' . $attribute . '" is not a number');
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setContext('database', $database)
            ->setParam('collectionId', $collectionId)
            ->setParam('tableId', $collectionId)
            ->setContext($this->getCollectionsEventsContext(), $collection);

        $response->dynamic($document, $this->getResponseModel());
    }
}
