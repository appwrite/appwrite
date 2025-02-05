<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files;

use Appwrite\Extend\Exception as ExtendException;
use Appwrite\Utopia\Database\Validator\Queries\FileTokens;
use Appwrite\Utopia\Response;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class ListFileTokens extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listFileTokens';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tokens/buckets/:bucketId/files/:fileId')
            ->desc('List tokens')
            ->groups(['api', 'tokens'])
            ->label('scope', 'tokens.read')
            ->label('usage.metric', 'tokens.requests.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
            ->label('sdk.namespace', 'tokens')
            ->label('sdk.method', 'list')
            ->label('sdk.description', '/docs/references/storage/list.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_RESOURCE_TOKEN_LIST)
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('fileId', '', new UID(), 'File unique ID.')
            ->param('queries', [], new FileTokens(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', FileTokens::ALLOWED_ATTRIBUTES), true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback(fn ($bucketId, $fileId, $queries, $response, $dbForProject) => $this->action($bucketId, $fileId, $queries, $response, $dbForProject));
    }

    public function action(string $bucketId, string $fileId, array $queries, Response $response, Database $dbForProject)
    {
        ['bucket' => $bucket, 'file' => $file] = $this->getFileAndBucket($dbForProject, $bucketId, $fileId);

        $queries = Query::parseQueries($queries);
        $queries[] = Query::equal('resourceType', ["files"]);
        $queries[] = Query::equal('resourceId', [$bucket->getInternalId() . ':' . $file->getInternalId()]);
        // Get cursor document if there was a cursor query
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $tokenId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('resourceTokens', $tokenId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(ExtendException::GENERAL_CURSOR_NOT_FOUND, "File token '{$tokenId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'tokens' => $dbForProject->find('resourceTokens', $queries),
            'total' => $dbForProject->count('resourceTokens', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_RESOURCE_TOKEN_LIST);
    }
}
