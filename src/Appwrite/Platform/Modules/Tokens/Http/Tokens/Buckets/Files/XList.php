<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files;

use Appwrite\Extend\Exception as ExtendException;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\FileTokens;
use Appwrite\Utopia\Response;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
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
            ->label('sdk', new Method(
                namespace: 'tokens',
                group: 'files',
                name: 'list',
                description: <<<EOT
                List all the tokens created for a specific file or bucket. You can use the query params to filter your results.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_RESOURCE_TOKEN_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('fileId', '', new UID(), 'File unique ID.')
            ->param('queries', [], new FileTokens(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', FileTokens::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $bucketId, string $fileId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Authorization $authorization)
    {
        ['bucket' => $bucket, 'file' => $file] = $this->getFileAndBucket($dbForProject, $authorization, $bucketId, $fileId);

        $queries = Query::parseQueries($queries);
        $queries[] = Query::equal('resourceType', [TOKENS_RESOURCE_TYPE_FILES]);
        $queries[] = Query::equal('resourceInternalId', [$bucket->getSequence() . ':' . $file->getSequence()]);
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
            'total' => $includeTotal ? $dbForProject->count('resourceTokens', $filterQueries, APP_LIMIT_COUNT) : 0,
        ]), Response::MODEL_RESOURCE_TOKEN_LIST);
    }
}
