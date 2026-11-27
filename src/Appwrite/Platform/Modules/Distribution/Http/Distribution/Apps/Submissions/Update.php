<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Submissions;

use Appwrite\Distribution\Submission\Step;
use Appwrite\Distribution\Submissions;
use Appwrite\Event\Event;
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
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;

class Update extends Action
{
    use HTTP;

    private const array RELEASED = [
        Step::Released,
        Step::Settled,
    ];

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
                Update a submission by its unique ID. Only the parameters you pass are changed. Whether the release goes live automatically after approval can change only until the submission is released to the store.
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
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $submissionId,
        ?bool $automatic,
        Response $response,
        Database $dbForProject,
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

        if (\is_null($automatic)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one parameter to update is required.');
        }

        $step = Step::tryFrom((string) $submission->getAttribute('step', ''));
        $active = Submissions::isActive((string) $submission->getAttribute('status'), (float) $submission->getAttribute('rollout', 1));

        if (!$active || \in_array($step, self::RELEASED, true)) {
            throw new Exception(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, "Submission '{$submission->getId()}' was already released to the store, so automatic can no longer change.");
        }

        $submission = $dbForProject->updateDocument('distributionSubmissions', $submission->getId(), new Document([
            'automatic' => $automatic,
        ]));

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('submissionId', $submission->getId());

        $response->dynamic($submission, Response::MODEL_DISTRIBUTION_SUBMISSION);
    }
}
