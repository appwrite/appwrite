<?php

namespace Appwrite\Platform\Modules\Advisor\Http\Reports;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteReport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/reports/:reportId')
            ->desc('Delete report')
            ->groups(['api', 'advisor'])
            ->label('scope', 'reports.write')
            ->label('event', 'reports.[reportId].delete')
            ->label('resourceType', RESOURCE_TYPE_REPORTS)
            ->label('audits.event', 'report.delete')
            ->label('audits.resource', 'report/{request.reportId}')
            ->label('abuse-key', 'projectId:{projectId},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'advisor',
                group: 'reports',
                name: 'deleteReport',
                description: '/docs/references/advisor/delete-report.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->param('reportId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Report ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('publisherForDeletes')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $reportId,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        DeletePublisher $publisherForDeletes,
        Event $queueForEvents
    ): void {
        $report = $dbForPlatform->skipFilters(
            fn () => $dbForPlatform->getDocument('reports', $reportId),
            ['subQueryReportInsights'],
        );

        if ($report->isEmpty() || $report->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::REPORT_NOT_FOUND);
        }

        if (!$dbForPlatform->deleteDocument('reports', $report->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove report from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_REPORT,
            document: $report,
        ));

        $queueForEvents
            ->setParam('reportId', $report->getId())
            ->setPayload($response->output($report, Response::MODEL_REPORT));

        $response->noContent();
    }
}
