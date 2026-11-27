<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Submissions;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Artifact\Status as ArtifactStatus;
use Appwrite\Distribution\Checks;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Stores;
use Appwrite\Distribution\Submission\Status;
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
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\Boolean;
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
                Submit a ready artifact of a release to a store track through the target's store connection. The submission runs in the background: its status moves from waiting through upload and review to published. Sending the same key again returns the existing submission instead of creating a second one.
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

        if ($artifact->getAttribute('status') !== ArtifactStatus::Ready->value) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_READY, "Artifact '{$artifact->getId()}' is '{$artifact->getAttribute('status')}'. Only a ready artifact can be submitted.");
        }

        $platform = Platform::from($target->getAttribute('platform'));

        if ($artifact->getAttribute('platform') !== $platform->value || $artifact->getAttribute('identifier') !== $target->getAttribute('identifier')) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_INVALID, "Artifact '{$artifact->getId()}' was built for '{$artifact->getAttribute('identifier')}' on {$artifact->getAttribute('platform')}, but target '{$target->getId()}' ships '{$target->getAttribute('identifier')}' on {$platform->value}.");
        }

        $this->assertConnection($dbForProject, $target, $platform);

        $track = $track ?: (string) $target->getAttribute('track', '');
        $this->assertTrack($distributionStores, $target, $track, $platform);

        $version = $this->getVersion($artifact, $platform);
        $key = $key ?: "{$target->getId()}:{$track}:" . ($version ?: $artifact->getId());

        $existing = $this->getExisting($dbForProject, $app, $key);

        if (!$existing->isEmpty()) {
            $queueForEvents->reset();
            $response->dynamic($existing, Response::MODEL_DISTRIBUTION_SUBMISSION);
            return;
        }

        if ($version !== '') {
            $this->assertVersion($dbForProject, $app, $target, $artifact, $platform, $track, $version);
        }

        $submissions = new Submissions(
            $dbForProject,
            $project,
            $distributionStores,
            new Schedules($dbForPlatform),
            $deviceForDistribution,
            $publisherForDistributionSubmissions,
        );

        $submission = $submissions->create($app, $release, $target, $artifact, $track, $automatic, $key);

        if ($submission === null) {
            $queueForEvents->reset();
            $response->dynamic($this->getExisting($dbForProject, $app, $key), Response::MODEL_DISTRIBUTION_SUBMISSION);
            return;
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('submissionId', $submission->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($submission, Response::MODEL_DISTRIBUTION_SUBMISSION);
    }

    private function getExisting(Database $dbForProject, Document $app, string $key): Document
    {
        return $dbForProject->findOne('distributionSubmissions', [
            Query::equal('appInternalId', [$app->getSequence()]),
            Query::equal('key', [$key]),
        ]);
    }

    private function getChild(Database $dbForProject, string $collection, string $id, Document $app, string $error): Document
    {
        $document = $dbForProject->getDocument($collection, $id);

        if ($document->isEmpty() || $document->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception($error);
        }

        return $document;
    }

    private function assertConnection(Database $dbForProject, Document $target, Platform $platform): void
    {
        $connectionId = (string) $target->getAttribute('connectionId', '');

        if ($connectionId === '') {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, "Target '{$target->getId()}' has no store connection. Attach one with updateTarget before submitting.");
        }

        $connection = $dbForProject->getDocument('distributionConnections', $connectionId);

        if ($connection->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $provider = Stores::provider($platform);

        if ($connection->getAttribute('provider') !== $provider->value) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, "A '{$platform->value}' target needs a '{$provider->value}' connection.");
        }
    }

    private function assertTrack(Stores $distributionStores, Document $target, string $track, Platform $platform): void
    {
        $tracks = $distributionStores->tracks(Stores::provider($platform));

        if ($track === '') {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, 'Pass a track or set a default track on the target. Use one of: ' . \implode(', ', $tracks) . '.');
        }

        if (!\in_array($track, $tracks, true)) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, "Track '{$track}' is not a valid track for {$platform->value}. Use one of: " . \implode(', ', $tracks) . '.');
        }

        $configuration = $target->getAttribute('configuration', []);

        if (Stores::isMissingGroups($track, \is_array($configuration) ? $configuration : [])) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, Checks::GROUPS_REQUIRED);
        }
    }

    private function assertVersion(Database $dbForProject, Document $app, Document $target, Document $artifact, Platform $platform, string $track, string $version): void
    {
        $artifacts = $dbForProject->find('distributionArtifacts', [
            Query::equal('appInternalId', [$app->getSequence()]),
            Query::equal('identifier', [$artifact->getAttribute('identifier')]),
            Query::equal($this->getVersionAttribute($platform), [$artifact->getAttribute($this->getVersionAttribute($platform))]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $submitted = $dbForProject->count('distributionSubmissions', [
            Query::equal('targetInternalId', [$target->getSequence()]),
            Query::equal('track', [$track]),
            Query::equal('artifactInternalId', \array_map(static fn (Document $document): string => (string) $document->getSequence(), $artifacts)),
            Query::equal('status', \array_values(\array_diff(Status::values(), [Status::Failed->value, Status::Canceled->value]))),
        ], 1);

        if ($submitted > 0) {
            throw new Exception(Exception::DISTRIBUTION_VERSION_INVALID, "Version {$version} was already submitted to the '{$track}' track. Submit an artifact with a higher version code.");
        }
    }

    private function getVersion(Document $artifact, Platform $platform): string
    {
        return (string) $artifact->getAttribute($this->getVersionAttribute($platform), '');
    }

    private function getVersionAttribute(Platform $platform): string
    {
        return match ($platform) {
            Platform::Ios => 'buildNumber',
            Platform::Android => 'versionCode',
        };
    }
}
