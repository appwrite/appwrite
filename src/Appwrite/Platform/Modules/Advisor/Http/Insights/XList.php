<?php

namespace Appwrite\Platform\Modules\Advisor\Http\Insights;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Insights;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listInsights';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reports/:reportId/insights')
            ->desc('List insights')
            ->groups(['api', 'advisor'])
            ->label('scope', 'insights.read')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('sdk', new Method(
                namespace: 'advisor',
                group: 'insights',
                name: 'listInsights',
                description: '/docs/references/advisor/list-insights.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT_LIST,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID.', false, ['dbForPlatform'])
            ->param('queries', [], new Insights(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Insights::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        // Skip the insights subquery — we're about to fetch a filtered, paginated slice ourselves.
        $report = $dbForPlatform->skipFilters(
            fn () => $dbForPlatform->getDocument('reports', $reportId),
            ['subQueryReportInsights'],
        );

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('projectInternalId', [$project->getSequence()]);
        $queries[] = Query::equal('reportInternalId', [$report->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $insightId = $cursor->getValue();
            $cursorDocument = $dbForPlatform->getDocument('insights', $insightId);

            if (
                $cursorDocument->isEmpty()
                || $cursorDocument->getAttribute('projectInternalId') !== $project->getSequence()
                || $cursorDocument->getAttribute('reportInternalId') !== $report->getSequence()
            ) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Insight '{$insightId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $insights = $dbForPlatform->find('insights', $queries);
            $total = $includeTotal ? $dbForPlatform->count('insights', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'insights' => $insights,
            'total' => $total,
        ]), Response::MODEL_INSIGHT_LIST);
    }
}
