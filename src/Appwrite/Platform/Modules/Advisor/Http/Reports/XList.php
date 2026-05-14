<?php

namespace Appwrite\Platform\Modules\Advisor\Http\Reports;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Reports;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listReports';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reports')
            ->desc('List reports')
            ->groups(['api', 'advisor'])
            ->label('scope', 'reports.read')
            ->label('resourceType', RESOURCE_TYPE_REPORTS)
            ->label('sdk', new Method(
                namespace: 'advisor',
                group: 'reports',
                name: 'listReports',
                description: '/docs/references/advisor/list-reports.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REPORT_LIST,
                    ),
                ]
            ))
            ->param('queries', [], new Reports(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Reports::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('projectInternalId', [$project->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $reportId = $cursor->getValue();
            $cursorDocument = $dbForPlatform->skipFilters(
                fn () => $dbForPlatform->getDocument('reports', $reportId),
                ['subQueryReportInsights'],
            );

            if ($cursorDocument->isEmpty() || $cursorDocument->getAttribute('projectInternalId') !== $project->getSequence()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Report '{$reportId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $reports = $dbForPlatform->skipFilters(
                fn () => $dbForPlatform->find('reports', $queries),
                ['subQueryReportInsights'],
            );
            $total = $includeTotal ? $dbForPlatform->count('reports', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        if (!empty($reports)) {
            $reportSequences = \array_map(fn (Document $r) => $r->getSequence(), $reports);

            $insights = $dbForPlatform->find('insights', [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('reportInternalId', $reportSequences),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            $insightsByReport = [];
            foreach ($insights as $insight) {
                $insightsByReport[$insight->getAttribute('reportInternalId')][] = $insight;
            }

            foreach ($reports as $report) {
                $report->setAttribute('insights', $insightsByReport[$report->getSequence()] ?? []);
            }
        }

        $response->dynamic(new Document([
            'reports' => $reports,
            'total' => $total,
        ]), Response::MODEL_REPORT_LIST);
    }
}
