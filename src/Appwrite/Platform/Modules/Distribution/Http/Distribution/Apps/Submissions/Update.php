<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Submissions;

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
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class Update extends Action
{
    use HTTP;

    private const array RELEASED = [
        Step::Released,
        Step::Settled,
    ];

    private const int LOCK_TTL = 60;

    private const float LOCK_TIMEOUT = 10.0;

    public static function getName(): string
    {
        return 'updateSubmission';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/submissions/:submissionId')
            ->desc('Update submission')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.submissions.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].submissions.[submissionId].update')
            ->label('audits.event', 'distribution.submission.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'submissions',
                name: 'updateSubmission',
                description: <<<EOT
                Update a submission by its unique ID. Only the parameters you pass are changed. Whether the release goes live automatically after approval can change only until the submission is released to the store. The rollout can change until then too, and afterwards only to raise a published Google Play staged rollout. Google Play never lowers a staged rollout: halt it with updateSubmissionStatus instead, and resume a halted rollout the same way before raising it. A rollout change is refused while a requested halt is still pending. A change after release is applied at the store in the background, so the returned submission still shows the current rollout.
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
            ->param('automatic', null, new Nullable(new Boolean()), 'Whether the release goes live automatically after the store approves it. When false, publish it with updateSubmissionStatus.', true)
            ->param('rollout', null, new Nullable(new Range(0, 1, Range::TYPE_FLOAT)), 'Fraction of users that receive the release, greater than 0 and at most 1. After release it can only raise a published Google Play staged rollout, and the change is applied at the store in the background. A halted rollout must be resumed with updateSubmissionStatus first.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('publisherForDistributionSubmissions')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $submissionId,
        ?bool $automatic,
        ?float $rollout,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Document $project,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions,
        callable $locks
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        try {
            [$submission, $changed] = $locks(
                Submissions::lock((string) $project->getSequence(), $submissionId),
                self::LOCK_TTL,
                fn (): array => $this->change($app, $submissionId, $automatic, $rollout, $dbForProject, $project, $publisherForDistributionSubmissions),
                self::LOCK_TIMEOUT,
            );
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED, 'The submission is busy. Try again.');
        }

        if ($changed) {
            $queueForEvents
                ->setParam('appId', $app->getId())
                ->setParam('submissionId', $submission->getId());
        } else {
            $queueForEvents->reset();
        }

        $response->dynamic($submission, Response::MODEL_DISTRIBUTION_SUBMISSION);
    }

    /**
     * @return array{Document, bool}
     */
    private function change(
        Document $app,
        string $submissionId,
        ?bool $automatic,
        ?float $rollout,
        Database $dbForProject,
        Document $project,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions
    ): array {
        $submission = $dbForProject->getDocument('distributionSubmissions', $submissionId);

        if ($submission->isEmpty() || $submission->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_NOT_FOUND);
        }

        if (\is_null($automatic) && \is_null($rollout)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one parameter to update is required.');
        }

        if (!\is_null($rollout)) {
            Submissions::assertRollout(Provider::from((string) $submission->getAttribute('provider')), $rollout);
        }

        $status = (string) $submission->getAttribute('status');
        $current = (float) $submission->getAttribute('rollout', 1);
        $released = \in_array(Step::tryFrom((string) $submission->getAttribute('step', '')), self::RELEASED, true);

        if (!$released && !Submissions::isActive($status, $current)) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' is {$status} and was never released to the store, so it can no longer change.");
        }

        if (!$released) {
            $changes = [];

            if (!\is_null($automatic) && $automatic !== (bool) $submission->getAttribute('automatic', true)) {
                $changes['automatic'] = $automatic;
            }

            if (!\is_null($rollout) && $rollout !== $current) {
                $changes['rollout'] = $rollout;
            }

            if ($changes === []) {
                return [$submission, false];
            }

            return [$dbForProject->updateDocument('distributionSubmissions', $submission->getId(), new Document($changes)), true];
        }

        if (!\is_null($automatic)) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' was already released to the store, so automatic can no longer change.");
        }

        Submissions::assertRolloutChange($submission, (float) $rollout);

        if ($rollout === $current) {
            return [$submission, false];
        }

        $queued = $publisherForDistributionSubmissions->enqueue(new DistributionSubmissionMessage(
            project: $project,
            action: DistributionSubmissionAction::Control,
            id: $submission->getId(),
            data: ['status' => Status::Published->value, 'rollout' => $rollout],
        ));

        if ($queued === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The rollout change was not accepted by the queue. Try again.');
        }

        return [$submission, true];
    }
}
