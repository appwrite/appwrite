<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects\Keys;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
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
            ->setHttpPath('/v1/organization/projects/:projectId/keys')
            ->desc('List project keys')
            ->groups(['api', 'organization'])
            ->label('scope', ['organization.projects.keys.read', 'keys.read'])
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'keys',
                name: 'listProjectKeys',
                description: <<<EOT
                Get a list of all API keys of a project in your organization.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_KEY_LIST,
                    )
                ]
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('queries', [], new Keys(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Keys::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        string $projectId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
        ?Key $apiKey,
    ) {
        $project = $this->getProject($projectId, $team, $dbForPlatform, $apiKey);

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

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => $total,
        ]), Response::MODEL_KEY_LIST);
    }
}
