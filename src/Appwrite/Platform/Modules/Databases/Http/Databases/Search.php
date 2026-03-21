<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Search extends Action
{
    public static function getName(): string
    {
        return 'searchDocuments';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/search')
            ->desc('Search documents across collections')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'databases',
                name: self::getName(),
                description: '/docs/references/databases/search-documents.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionIds', [], new ArrayList(new UID(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of collection IDs to search within.', false)
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, array $collectionIds, array $queries, UtopiaResponse $response, Database $dbForProject, Authorization $authorization): void
    {
        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $collections = [];
        foreach ($collectionIds as $collectionId) {
            $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
            if (!$collection->isEmpty() && ($collection->getAttribute('enabled', false) || $isAPIKey || $isPrivilegedUser)) {
                $collections[] = $collection;
            }
        }

        if (empty($collections)) {
            // If no collections specified or found, fallback to all enabled collections in the database
            $collections = $authorization->skip(fn () => $dbForProject->find('database_' . $database->getSequence(), [
                Query::equal('enabled', [true]),
                Query::limit(APP_LIMIT_SUBQUERY)
            ]));
        }

        try {
            $documents = $dbForProject->findAcrossCollections($collections, $queries);
            $total = count($documents); // findAcrossCollections returns aggregated results
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $operations = 0;
        $collectionsCache = [];
        foreach ($documents as $document) {
            // Find the correct collection for processing
            $collectionId = $document->getCollection();
            if (!isset($collectionsCache[$collectionId])) {
                $collectionsCache[$collectionId] = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
            }

            $this->processDocument(
                database: $database,
                collection: $collectionsCache[$collectionId],
                document: $document,
                dbForProject: $dbForProject,
                collectionsCache: $collectionsCache,
                authorization: $authorization,
                operations: $operations
            );
        }

        $response->dynamic(new Document([
            'total' => $total,
            'documents' => $documents,
        ]), $this->getResponseModel());
    }
}
