<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\ListSelection;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    // cached mapping of columns to their subQuery filters
    private static ?array $attributeToSubQueryFilters = null;

    public static function getName()
    {
        return 'listProjects';
    }

    protected function getQueriesValidator(): Validator
    {
        return new Projects();
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects')
            ->desc('List projects')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'projects',
                name: 'list',
                description: <<<EOT
                Get a list of all projects. You can use the query params to filter your results. 
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT_LIST
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('queries', [], $this->getQueriesValidator(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Projects::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(array $queries, string $search, bool $includeTotal, Request $request, Response $response, Database $dbForPlatform)
    {
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
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $projectId = $cursor->getValue();
            $cursorDocument = $dbForPlatform->getDocument('projects', $projectId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Project '{$projectId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $selectQueries = Query::groupByType($queries)['selections'] ?? [];
            $filterQueries = Query::groupByType($queries)['filters'];

            $projects = $this->find($dbForPlatform, $queries, $selectQueries);
            $total = $includeTotal ? $dbForPlatform->count('projects', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (Order $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->addFilter(new ListSelection($selectQueries, 'projects'));

        $response->dynamic(new Document([
            'projects' => $projects,
            'total' => $total,
        ]), Response::MODEL_PROJECT_LIST);
    }

    // Build mapping of columns to their subQuery filters
    private static function getAttributeToSubQueryFilters(): array
    {
        if (self::$attributeToSubQueryFilters !== null) {
            return self::$attributeToSubQueryFilters;
        }

        self::$attributeToSubQueryFilters = [];

        $collections = Config::getParam('collections', []);
        $projectAttributes = $collections['platform']['projects']['attributes'] ?? [];

        foreach ($projectAttributes as $attribute) {
            $attributeId = $attribute['$id'] ?? null;
            $filters = $attribute['filters'] ?? [];

            if ($attributeId === null || empty($filters)) {
                continue;
            }

            // extract only subQuery filters
            $subQueryFilters = \array_filter($filters, function ($filter) {
                return \str_starts_with($filter, 'subQuery');
            });

            if (!empty($subQueryFilters)) {
                self::$attributeToSubQueryFilters[$attributeId] = \array_values($subQueryFilters);
            }
        }

        return self::$attributeToSubQueryFilters;
    }

    private function find(Database $dbForPlatform, array $queries, array $selectQueries): array
    {
        if (empty($selectQueries)) {
            return $dbForPlatform->find('projects', $queries);
        }

        $selectedAttributes = [];
        foreach ($selectQueries as $query) {
            foreach ($query->getValues() as $value) {
                $selectedAttributes[] = $value;
            }
        }

        if (\in_array('*', $selectedAttributes)) {
            return $dbForPlatform->find('projects', $queries);
        }

        $filtersToSkipMap = [];
        $selectedAttributesMap = \array_flip($selectedAttributes);
        $attributeToSubQueryFilters = self::getAttributeToSubQueryFilters();

        foreach ($attributeToSubQueryFilters as $attributeName => $subQueryFilters) {
            if (!isset($selectedAttributesMap[$attributeName])) {
                foreach ($subQueryFilters as $filter) {
                    $filtersToSkipMap[$filter] = true;
                }
            }
        }

        $filtersToSkip = \array_keys($filtersToSkipMap);

        return empty($filtersToSkip)
            ? $dbForPlatform->find('projects', $queries)
            : $dbForPlatform->skipFilters(fn () => $dbForPlatform->find('projects', $queries), $filtersToSkip);
    }
}
