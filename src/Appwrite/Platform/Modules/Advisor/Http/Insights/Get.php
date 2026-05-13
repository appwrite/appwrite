<?php

namespace Appwrite\Platform\Modules\Advisor\Http\Insights;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getInsight';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reports/:reportId/insights/:insightId')
            ->desc('Get insight')
            ->groups(['api', 'advisor'])
            ->label('scope', 'insights.read')
            ->label('resourceType', RESOURCE_TYPE_INSIGHTS)
            ->label('sdk', new Method(
                namespace: 'advisor',
                group: 'insights',
                name: 'getInsight',
                description: '/docs/references/advisor/get-insight.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_INSIGHT,
                    ),
                ]
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Parent report ID.', false, ['dbForPlatform'])
            ->param('insightId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Insight ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        string $insightId,
        Response $response,
        Document $project,
        Database $dbForPlatform
    ) {
        // Skip the insights subquery — we only need ownership metadata.
        $report = $dbForPlatform->skipFilters(
            fn () => $dbForPlatform->getDocument('reports', $reportId),
            ['subQueryReportInsights'],
        );

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        $insight = $dbForPlatform->getDocument('insights', $insightId);

        if (
            $insight->isEmpty()
            || $insight->getAttribute('projectInternalId') !== $project->getSequence()
            || $insight->getAttribute('reportInternalId') !== $report->getSequence()
        ) {
            throw new Exception(Exception::INSIGHT_NOT_FOUND);
        }

        $response->dynamic($insight, Response::MODEL_INSIGHT);
    }
}
