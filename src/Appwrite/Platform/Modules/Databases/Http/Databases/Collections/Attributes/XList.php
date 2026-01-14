<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Attributes;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class XList extends Action
{
    public static function getName(): string
    {
        return 'listAttributes';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes')
            ->desc('List attributes')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/list-attributes.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.listColumns',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('queries', [], new Attributes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Attributes::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, bool $includeTotal, UtopiaResponse $response, Database $dbForProject, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        \array_push(
            $queries,
            Query::equal('databaseInternalId', [$database->getSequence()]),
            Query::equal('collectionInternalId', [$collection->getSequence()])
        );

        $cursor = \array_filter(
            $queries,
            fn ($query) => \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE])
        );
        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $attributeId = $cursor->getValue();
            try {
                $cursorDocument = $dbForProject->findOne('attributes', [
                    Query::equal('databaseInternalId', [$database->getSequence()]),
                    Query::equal('collectionInternalId', [$collection->getSequence()]),
                    Query::equal('key', [$attributeId]),
                ]);
            } catch (QueryException $e) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }

            if ($cursorDocument->isEmpty()) {
                $type = ucfirst($this->getContext());
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "$type '$attributeId' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $attributes = $dbForProject->find('attributes', $queries);
            $total = $includeTotal ? $dbForProject->count('attributes', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            $documents = $this->isCollectionsAPI() ? 'documents' : 'rows';
            $attribute = $this->isCollectionsAPI() ? 'attribute' : 'column';
            $message = "The order $attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all $documents order $attribute values are non-null.";
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, $message);
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        foreach ($attributes as $attribute) {
            if ($attribute->getAttribute('type') === Database::VAR_STRING) {
                $filters = $attribute->getAttribute('filters', []);
                $attribute->setAttribute('encrypt', in_array('encrypt', $filters));
            }
        }

        $response->dynamic(new Document([
            'total' => $total,
            $this->getSDKGroup() => $attributes,
        ]), $this->getResponseModel());
    }
}
