<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Keys;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectKeys';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/keys')
            ->httpAlias('/v1/projects/:projectId/keys')
            ->desc('List project keys')
            ->groups(['api', 'project'])
            ->label('scope', 'project.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'keys',
                name: 'listKeys',
                description: <<<EOT
                Get a list of all API keys from the current project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_KEY_LIST,
                    )
                ]
            ))
            ->param('queries', [], new Keys(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Keys::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        array $queries,
        bool $includeTotal,
        Document $project,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        ?Key $apiKey,
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Backwards compatibility
        if (\count(Query::getByType($queries, [Query::TYPE_LIMIT])) === 0) {
            $queries[] = Query::limit(5000);
        }

        $queries[] = Query::equal('resourceType', ['projects']);
        $queries[] = Query::equal('resourceInternalId', [$project->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $keyId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForPlatform->findOne('keys', [
                Query::equal('$id', [$keyId]),
                Query::equal('resourceType', ['projects']),
                Query::equal('resourceInternalId', [$project->getSequence()]),
            ]));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Key '{$keyId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $keys = $authorization->skip(fn () => $dbForPlatform->find('keys', $queries));
            $total = $includeTotal ? $authorization->skip(fn () => $dbForPlatform->count('keys', $filterQueries, APP_LIMIT_COUNT)) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $isProjectApiKey = $apiKey !== null && !empty($apiKey->getProjectId());

        if ($isProjectApiKey) {
            foreach ($keys as $key) {
                $key->setAttribute('secret', '');
            }
        }

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => $total,
        ]), Response::MODEL_KEY_LIST);
    }
}
