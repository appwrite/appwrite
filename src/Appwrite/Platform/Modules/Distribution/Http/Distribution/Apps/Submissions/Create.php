<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Submissions;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Stores;
use Appwrite\Distribution\Submissions;
use Appwrite\Event\Event;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSubmission';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/submissions')
            ->desc('Create submission')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.submissions.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].submissions.[submissionId].create')
            ->label('audits.event', 'distribution.submission.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'submissions',
                name: 'createSubmission',
                description: <<<EOT
                Submit a ready artifact of a release to a store track through the target's store connection. The submission runs in the background: its status moves from waiting through upload and review to published. Sending the same key again returns the existing submission instead of creating a second one. Pass a rollout below 1 to start a Google Play staged rollout at that fraction of users, and raise it later with updateSubmission.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_SUBMISSION,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('releaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of the release the submission belongs to.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of the target to submit for. Its store connection receives the artifact.', false, ['dbForProject'])
            ->param('artifactId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'ID of the artifact to submit. It must be ready and match the target platform and identifier.', false, ['dbForProject'])
            ->param('track', '', new Text(64, 0), 'Store track to submit to. Leave empty to use the target default. iOS accepts testflight, testflight-external and production; Android accepts internal, alpha, beta and production.', true)
            ->param('automatic', true, new Boolean(), 'Whether the release goes live automatically after the store approves it. When false, publish it with updateSubmissionStatus.', true)
            ->param('key', '', new Text(128, 0), 'Idempotency key. A second request with the same key returns the existing submission. Defaults to the target ID, track and version code joined by colons. Max length: 128 chars.', true)
            ->param('rollout', 1.0, new Range(0, 1, Range::TYPE_FLOAT), 'Fraction of users that receive the release, greater than 0 and at most 1. Defaults to 1, a full rollout. Apple accepts only 1, because its phased release percentages are fixed.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('distributionStores')
            ->inject('deviceForDistribution')
            ->inject('publisherForDistributionSubmissions')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $releaseId,
        string $targetId,
        string $artifactId,
        string $track,
        bool $automatic,
        string $key,
        float $rollout,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Stores $distributionStores,
        Device $deviceForDistribution,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $release = $this->getChild($dbForProject, 'distributionReleases', $releaseId, $app, Exception::DISTRIBUTION_RELEASE_NOT_FOUND);
        $target = $this->getChild($dbForProject, 'distributionTargets', $targetId, $app, Exception::DISTRIBUTION_TARGET_NOT_FOUND);
        $artifact = $this->getChild($dbForProject, 'distributionArtifacts', $artifactId, $app, Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);

        $submissions = new Submissions(
            $dbForProject,
            $project,
            $distributionStores,
            new Schedules($dbForPlatform),
            $deviceForDistribution,
            $publisherForDistributionSubmissions,
        );

        $track = $track ?: (string) $target->getAttribute('track', '');
        $submissions->accept($app, $target, $artifact, $track);

        Submissions::assertRollout(Stores::provider(Platform::from((string) $target->getAttribute('platform', ''))), $rollout);

        $key = $key ?: Submissions::key($target, $artifact, $track);
        $existing = $submissions->existing($app, $key);

        if (!$existing->isEmpty()) {
            $queueForEvents->reset();
            $response->dynamic($existing, Response::MODEL_DISTRIBUTION_SUBMISSION);
            return;
        }

        $submissions->assertVersion($app, $target, $artifact, $track);

        $submission = $submissions->create($app, $release, $target, $artifact, $track, $automatic, $key, $rollout);

        if ($submission === null) {
            $queueForEvents->reset();
            $response->dynamic($submissions->existing($app, $key), Response::MODEL_DISTRIBUTION_SUBMISSION);
            return;
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('submissionId', $submission->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($submission, Response::MODEL_DISTRIBUTION_SUBMISSION);
    }

    private function getChild(Database $dbForProject, string $collection, string $id, Document $app, string $error): Document
    {
        $document = $dbForProject->getDocument($collection, $id);

        if ($document->isEmpty() || $document->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception($error);
        }

        return $document;
    }
}
