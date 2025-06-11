<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Collections;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listCollections';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_COLLECTION_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections')
            ->desc('List collections')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/list-collections.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('queries', [], new Collections(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Collections::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, array $queries, string $search, UtopiaResponse $response, Database $dbForProject): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $collectionIdId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionIdId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, ucfirst($this->getContext()) . " '$collectionIdId' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $collections = $dbForProject->find('database_' . $database->getSequence(), $queries);
            $total = $dbForProject->count('database_' . $database->getSequence(), $filterQueries, APP_LIMIT_COUNT);
        } catch (OrderException) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL);
        } catch (QueryException) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID);
        }

        $response->dynamic(new Document([
            'total' => $total,
            $this->getSdkGroup() => $collections,
        ]), $this->getResponseModel());
    }
}
