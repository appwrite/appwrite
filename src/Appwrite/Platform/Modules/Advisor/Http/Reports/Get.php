<?php

namespace Appwrite\Platform\Modules\Advisor\Http\Reports;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reports/:reportId')
            ->desc('Get report')
            ->groups(['api', 'advisor'])
            ->label('scope', 'reports.read')
            ->label('resourceType', RESOURCE_TYPE_REPORTS)
            ->label('sdk', new Method(
                namespace: 'advisor',
                group: 'reports',
                name: 'getReport',
                description: '/docs/references/advisor/get-report.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REPORT,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Report ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        $report = $dbForPlatform->skipFilters(
            fn () => $dbForPlatform->getDocument('reports', $reportId),
            ['subQueryReportInsights'],
        );

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        $insights = $dbForPlatform->find('insights', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('reportInternalId', [$report->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $report->setAttribute('insights', $insights);

        $response->dynamic($report, Response::MODEL_REPORT);
    }
}
