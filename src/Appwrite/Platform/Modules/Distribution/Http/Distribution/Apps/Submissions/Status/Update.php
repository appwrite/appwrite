<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Submissions\Status;

use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Submission\Status;
use Appwrite\Distribution\Submission\Step;
use Appwrite\Distribution\Submissions;
use Appwrite\Event\Event;
use Appwrite\Event\Message\DistributionSubmission as DistributionSubmissionMessage;
use Appwrite\Event\Message\DistributionSubmissionAction;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Release;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    private const array REQUESTABLE = [
        Status::Canceled,
        Status::Halted,
        Status::Published,
    ];

    private const array PUBLISHABLE = [
        Status::Approved,
        Status::Halted,
    ];

    private const array RELEASED = [
        Step::Released->value,
        Step::Settled->value,
    ];

    private const array TESTFLIGHT = [
        Release::TRACK_TESTFLIGHT,
        Release::TRACK_TESTFLIGHT_EXTERNAL,
    ];

    public static function getName(): string
    {
        return 'updateSubmissionStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/submissions/:submissionId/status')
            ->desc('Update submission status')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.submissions.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].submissions.[submissionId].update')
            ->label('audits.event', 'distribution.submission.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'submissions',
                name: 'updateSubmissionStatus',
                description: <<<EOT
                Request a status change for a submission. Pass `canceled` to stop it before review or withdraw it from review, `halted` to pause a staged or phased rollout, or `published` to resume a halted rollout or release an approved version that does not go live automatically. Google Play cannot withdraw a release once it is sent, so `canceled` is refused for a Google Play submission after that point. The change is applied at the store in the background, so the returned submission still shows the current status.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_SUBMISSION,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('submissionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Submission ID.', false, ['dbForProject'])
            ->param('status', '', new WhiteList(\array_map(static fn (Status $status): string => $status->value, self::REQUESTABLE), true), 'Requested status: canceled, halted or published.', enum: new Enum(name: 'DistributionSubmissionTransition'))
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('publisherForDistributionSubmissions')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $submissionId,
        string $status,
        Response $response,
        Document $project,
        Database $dbForProject,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $submission = $dbForProject->getDocument('distributionSubmissions', $submissionId);

        if ($submission->isEmpty() || $submission->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_NOT_FOUND);
        }

        $current = Status::from((string) $submission->getAttribute('status'));
        $requested = Status::from($status);

        if (!$this->canRequest($current, $requested, (float) $submission->getAttribute('rollout', 1))) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' cannot change from '{$current->value}' to '{$requested->value}'.");
        }

        if ($requested === Status::Published && Submissions::isReleasedOnApproval($submission)) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' cannot change from '{$current->value}' to '{$requested->value}'. " . Submissions::RELEASED_ON_APPROVAL);
        }

        if ($requested === Status::Canceled && $this->isReleasedToGoogle($submission)) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' was already sent to Google Play, which cannot withdraw a release once it is sent.");
        }

        if ($requested === Status::Canceled && $this->isReleasedToTestFlight($submission)) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' was already sent to TestFlight, where a build cannot be withdrawn from beta review.");
        }

        $queued = $publisherForDistributionSubmissions->enqueue(new DistributionSubmissionMessage(
            project: $project,
            action: DistributionSubmissionAction::Control,
            id: $submission->getId(),
            data: ['status' => $requested->value],
        ));

        if ($queued === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The status change was not accepted by the queue. Try again.');
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('submissionId', $submission->getId());

        $response->dynamic($submission, Response::MODEL_DISTRIBUTION_SUBMISSION);
    }

    private function canRequest(Status $current, Status $requested, float $rollout): bool
    {
        if (!Submissions::isActive($current->value, $rollout)) {
            return false;
        }

        if ($requested === Status::Published && !\in_array($current, self::PUBLISHABLE, true)) {
            return false;
        }

        return $current->canTransition($requested);
    }

    private function isReleasedToGoogle(Document $submission): bool
    {
        return $submission->getAttribute('provider') === Provider::Google->value
            && \in_array($submission->getAttribute('step'), self::RELEASED, true);
    }

    private function isReleasedToTestFlight(Document $submission): bool
    {
        return $submission->getAttribute('provider') === Provider::Apple->value
            && \in_array($submission->getAttribute('track'), self::TESTFLIGHT, true)
            && \in_array($submission->getAttribute('step'), self::RELEASED, true);
    }
}
