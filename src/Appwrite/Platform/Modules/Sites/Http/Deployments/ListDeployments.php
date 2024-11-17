<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Deployments;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class ListDeployments extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listDeployments';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/deployments')
            ->desc('List deployments')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'listDeployments')
            ->label('sdk.description', '/docs/references/sites/list-deployments.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_DEPLOYMENT_LIST)
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('queries', [], new Deployments(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Deployments::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForConsole')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, array $queries, string $search, Response $response, Document $project, Database $dbForProject, Database $dbForConsole)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Set resource queries
        $queries[] = Query::equal('resourceInternalId', [$site->getInternalId()]);
        $queries[] = Query::equal('resourceType', ['sites']);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $deploymentId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('deployments', $deploymentId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Deployment '{$deploymentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForProject->find('deployments', $queries);
        $total = $dbForProject->count('deployments', $filterQueries, APP_LIMIT_COUNT);

        foreach ($results as $result) {
            $build = $dbForProject->getDocument('builds', $result->getAttribute('buildId', ''));
            $result->setAttribute('status', $build->getAttribute('status', 'processing'));
            $result->setAttribute('buildLogs', $build->getAttribute('logs', ''));
            $result->setAttribute('buildTime', $build->getAttribute('duration', 0));
            $result->setAttribute('buildSize', $build->getAttribute('size', 0));
            $result->setAttribute('size', $result->getAttribute('size', 0));

            $rule = Authorization::skip(fn () => $dbForConsole->findOne('rules', [
                Query::equal("projectInternalId", [$project->getInternalId()]),
                Query::equal("resourceType", ["deployment"]),
                Query::equal("resourceInternalId", [$result->getInternalId()])
            ]));

            if (!empty($rule)) {
                $result->setAttribute('domain', $rule->getAttribute('domain', ''));
            }
        }

        $response->dynamic(new Document([
            'deployments' => $results,
            'total' => $total,
        ]), Response::MODEL_DEPLOYMENT_LIST);
    }
}
