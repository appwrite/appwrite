<?php

namespace Appwrite\Distribution;

use Appwrite\Auth\JobsSecret;
use Appwrite\Distribution\Artifact\Status as ArtifactStatus;
use Appwrite\Distribution\Build\Runner;
use Appwrite\Distribution\Build\Stage;
use Appwrite\Distribution\Build\Status as BuildStatus;
use Appwrite\Distribution\Lease\Status as LeaseStatus;
use Appwrite\Distribution\Runner\Platform as RunnerPlatform;
use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Validator\VariableKey;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\ReadFormat;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Exception\TimeoutException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\ReadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UnarchiveArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UploadArtifact;
use OpenRuntimes\Orchestrator\Model\Callback;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Psr7\Stream;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\DeviceType;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Signing material never enters a job specification, which stays readable until the job object is removed.
 */
readonly class Builds
{
    /**
     * A job stage of an uploaded artifact only. Builds and their logs record the `Stage` values.
     */
    public const string STAGE_INSPECT = 'inspect';
    public const string STAGE_SIGN = Stage::Sign->value;
    public const string STAGE_BUILD = Stage::Build->value;

    /**
     * The prefix of every orchestrator job id. Runner lease ids are bare hex, so a build-plane event never names a lease.
     */
    public const string JOB = 'dist-';

    private const string BUNDLE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-';

    public const string ERROR_ARTIFACT_MALFORMED = 'artifact_malformed';
    public const string ERROR_ARTIFACT_INVALID = 'artifact_invalid';
    public const string ERROR_ARTIFACT_SIZE_MISMATCH = 'artifact_size_mismatch';
    public const string ERROR_ARTIFACT_IDENTIFIER_UNKNOWN = 'artifact_identifier_unknown';
    public const string ERROR_ARTIFACT_UNSIGNED = 'artifact_unsigned';
    public const string ERROR_SIGNING_INPUT_INVALID = 'signing_input_invalid';
    public const string ERROR_ARTIFACT_ALREADY_SIGNED = 'artifact_already_signed';
    public const string ERROR_SIGNING_FAILED = 'signing_failed';
    public const string ERROR_SIGNING_VERIFICATION_FAILED = 'signing_verification_failed';
    public const string ERROR_SIGNING_BUNDLE_INVALID = 'signing_bundle_invalid';
    public const string ERROR_JOB_TIMEOUT = 'job_timeout';
    public const string ERROR_JOB_FAILED = 'job_failed';
    public const string ERROR_JOB_LOST = 'job_lost';
    public const string ERROR_BUILD_FAILED = 'build_failed';
    public const string ERROR_BUILD_TIMEOUT = 'build_timeout';
    public const string ERROR_BUILD_OUT_OF_MEMORY = 'build_out_of_memory';
    public const string ERROR_BUILD_LOST = 'build_lost';
    public const string ERROR_BUILD_INVALID = 'build_invalid';
    public const string ERROR_BUILD_ALREADY_SIGNED = 'build_already_signed';
    public const string ERROR_BUILD_KEYSTORE_REQUIRED = 'build_keystore_required';
    public const string ERROR_BUILD_CANCELED = 'build_canceled';
    public const string ERROR_BUILD_OUTPUT_INVALID = 'build_output_invalid';
    public const string ERROR_BUILD_SOURCE_FAILED = 'build_source_failed';
    public const string ERROR_RUNNER_UNAVAILABLE = 'distribution_runner_unavailable';
    public const string ERROR_VARIABLE_INVALID = 'variable_invalid';
    public const string ERROR_RUNNER_LOST = 'runner_lost';

    public const int MAX_ATTEMPTS = 3;

    public const int MAX_VERSION_CODE = 2100000000;

    public const string ARTIFACT_RETENTION = '7776000';

    public const array ACTIVE = [
        BuildStatus::Waiting->value,
        BuildStatus::Processing->value,
        BuildStatus::Building->value,
        BuildStatus::Signing->value,
    ];

    public const array TERMINAL = [
        BuildStatus::Ready->value,
        BuildStatus::Failed->value,
        BuildStatus::Canceled->value,
    ];

    /**
     * Three Java runs at the tools image's default DISTRIBUTION_MAX_JAVA_SECONDS of 600.
     */
    public const int TIMEOUT = 1800;

    /**
     * A remote download is a single attempt that must move up to the 4 GB upload limit.
     */
    public const int TRANSFER_TIMEOUT = 900;

    public const int SIGNING_TIMEOUT = 60;

    /**
     * How long post-job artifacts can still run after the worker exits: Docker tries each upload four times.
     */
    public const int POST_BUDGET = (self::TRANSFER_TIMEOUT + self::SIGNING_TIMEOUT) * self::TRANSFER_ATTEMPTS;

    public const int BUILD_POST_BUDGET = (self::TRANSFER_TIMEOUT + self::TRANSFER_TIMEOUT + self::SIGNING_TIMEOUT + self::SIGNING_TIMEOUT) * self::TRANSFER_ATTEMPTS;

    /**
     * Added to a build job's deadline, which on Kubernetes also covers scheduling, the image pull and the source download.
     */
    public const int SETUP = 600;

    /**
     * Wider than the orchestrator SDK's 300 s, because a healthy orchestrator can
     * deliver an event about 300 s late while its circuit breaker is open.
     */
    private const int TOLERANCE = 900;

    private const string PLANE_BUILD = 'build';

    private const string PLANE_SIGNING = 'signing';

    public const string WORKSPACE = '/mnt/code';

    public const string STORAGE = '/mnt/storage';

    private const string INSPECT_COMMAND = '/usr/local/distribution/inspect.sh';

    private const string SIGN_COMMAND = '/usr/local/distribution/sign-android.sh';

    private const string BUILD_COMMAND = '/usr/local/distribution/build.sh';

    /**
     * Build outputs are mounted outside the workspace, because the sidecar reads `result.json` only from `/mnt/code/output`.
     */
    private const string OUTPUT = '/mnt/output';

    private const string CACHE = '/mnt/cache';

    private const string SIGNING_ARTIFACT = 'signing';

    private const string SIGNING_BUNDLE = 'signing/bundle.json';

    private const int SIGNING_KEY_BYTES = 32;

    private const int TRANSFER_ATTEMPTS = 4;

    private const float CPU = 1.0;

    private const int MEMORY = 2048;

    private const int BUILD_TIMEOUT = 3600;

    private const string SPECIFICATION = 'd-4vcpu-8gb';

    private const string INTERNAL = 'An internal error occurred while starting the build. Please try again, and contact support if the problem persists.';

    private const array MESSAGES = [
        self::ERROR_BUILD_FAILED => 'The build failed. Check the build logs, then start a new build.',
        self::ERROR_BUILD_TIMEOUT => 'The build ran longer than the timeout of its target.',
        self::ERROR_BUILD_OUT_OF_MEMORY => 'The build ran out of memory. Choose a larger specification for the target, then start a new build.',
        self::ERROR_BUILD_LOST => 'The build stopped reporting progress. Start a new build.',
        self::ERROR_BUILD_INVALID => 'The build settings are invalid, or the build did not produce exactly one readable bundle with the package name and version code of the target. Check the build logs.',
        self::ERROR_BUILD_ALREADY_SIGNED => 'The build produced a signed bundle. Remove the release signing config so Distribution can sign the bundle, then start a new build.',
        self::ERROR_BUILD_KEYSTORE_REQUIRED => 'Your build script requires a keystore file; guard it or remove the signing config.',
        self::ERROR_BUILD_CANCELED => 'The build was canceled.',
        self::ERROR_BUILD_OUTPUT_INVALID => 'The build output does not match the target: its type, package name or version code differs.',
        self::ERROR_BUILD_SOURCE_FAILED => 'The source could not be downloaded from the repository. Check that the Git installation still has access, then start a new build.',
        self::ERROR_RUNNER_UNAVAILABLE => 'No macOS runner is available to build for iOS. Register a runner, then start a new build.',
        self::ERROR_VARIABLE_INVALID => 'A variable key is not a valid environment variable name. Update or delete the variable, then start a new build.',
        self::ERROR_RUNNER_LOST => 'A macOS runner failed every attempt to build. Check that your macOS runners are online and healthy, then start a new build.',
    ];

    public function __construct(
        private Jobs $jobs,
        private Jobs $signingJobs,
        protected Database $dbForProject,
        protected Database $dbForPlatform,
        protected Document $project,
        protected array $platform,
        protected DistributionBuildPublisher $publisher,
        protected Cache $cache,
    ) {
    }

    /**
     * Version codes come only from the counter's atomic increments, so concurrent builds never share one.
     */
    public function create(Document $app, Document $target, Document $build, Git $vcs, string $owner, string $repository, ?int $latest = null, ?Checks $checks = null): Document
    {
        if ($app->getAttribute('enabled', true) === false) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The app is disabled. Enable it, then start a build.');
        }

        if ($app->getAttribute('installationId', '') === '' || $app->getAttribute('providerRepositoryId', '') === '' || !$vcs->supportsRepositoryArchives()) {
            throw new Exception(Exception::DISTRIBUTION_REPOSITORY_MISSING);
        }

        if (!($this->project->getAttribute('apis', [])['rest'] ?? true)) {
            throw new Exception(Exception::GENERAL_API_DISABLED);
        }

        $specifications = Config::getParam('distribution')['specifications'];
        $specification = (string) $target->getAttribute('specification', '') ?: System::getEnv('_APP_DISTRIBUTION_BUILD_SPECIFICATION', self::SPECIFICATION);
        $resources = $specifications[$specification] ?? null;
        if ($resources === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Specification '{$specification}' is not a build specification. Use one of: " . \implode(', ', \array_keys($specifications)) . '.');
        }

        $manual = $target->getAttribute('versionStrategy', VersionStrategy::Automatic->value) === VersionStrategy::Manual->value;
        if ($manual && (int) $build->getAttribute('versionCode', 0) <= 0) {
            throw new Exception(Exception::DISTRIBUTION_VERSION_INVALID, 'The target uses the manual version strategy, so every build needs a version code.');
        }

        $this->admit($app, $build);

        $code = $manual ? (int) $build->getAttribute('versionCode') : $this->allocate($target, $latest);
        $platform = (string) $target->getAttribute('platform', '');

        $build = $this->dbForProject->createDocument('distributionBuilds', new Document([
            ...$build->getArrayCopy(),
            'appId' => $app->getId(),
            'appInternalId' => (string) $app->getSequence(),
            'targetId' => $target->getId(),
            'targetInternalId' => (string) $target->getSequence(),
            'platform' => $platform,
            'status' => BuildStatus::Waiting->value,
            'stage' => '',
            'attempt' => 1,
            'runner' => ($platform === Platform::Ios->value ? Runner::Pool : Runner::Orchestrator)->value,
            'jobId' => '',
            'providerRepositoryOwner' => $owner,
            'providerRepositoryName' => $repository,
            'providerRepositoryUrl' => (string) $build->getAttribute('providerRepositoryUrl', '') ?: $vcs->getRepositoryUrl($owner, $repository),
            'providerRootDirectory' => (string) $app->getAttribute('providerRootDirectory', ''),
            ...($platform === Platform::Ios->value ? ['versionCode' => null, 'buildNumber' => (string) $code] : ['versionCode' => $code]),
            'specification' => $specification,
            'cpus' => (int) $resources['cpus'],
            'memory' => (int) $resources['memory'],
            'timeout' => $this->timeout($target),
            'logsSize' => 0,
        ]));

        (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $build, 1);

        $this->preflight($app, $target, $build, $checks);

        $this->dbForProject->updateDocument('distributionApps', $app->getId(), new Document([
            'latestBuildId' => $build->getId(),
            'latestBuildInternalId' => (string) $build->getSequence(),
            'latestBuildStatus' => $build->getAttribute('status'),
            'latestBuildCreatedAt' => $build->getCreatedAt() ?: DateTime::now(),
        ]));

        if ($this->publisher->enqueue(new DistributionBuildMessage($this->project, DistributionBuildAction::Dispatch, $build->getId())) === false) {
            $this->fail($build, self::ERROR_BUILD_FAILED);

            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The build could not be queued. Try again.');
        }

        $this->report($app, $build, $vcs);

        return $this->dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * A `processing` build that never started is the same attempt submitted again, which the
     * deterministic job id makes a 409 while the job exists. The source URL is created here,
     * because a provider's archive URL lasts minutes.
     */
    public function dispatch(Document $build, Git $vcs): Document
    {
        $build = $this->dbForProject->getDocument('distributionBuilds', $build->getId());
        $status = $build->getAttribute('status');
        $resubmit = $status === BuildStatus::Processing->value && empty($build->getAttribute('startedAt'));
        if ($build->isEmpty() || ($status !== BuildStatus::Waiting->value && !$resubmit)) {
            return $build;
        }

        if ($build->getAttribute('platform') === Platform::Ios->value) {
            return $this->lease($build);
        }

        $app = $this->dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        $target = $this->dbForProject->getDocument('distributionTargets', (string) $build->getAttribute('targetId', ''));
        if ($app->isEmpty() || $target->isEmpty()) {
            return $this->fail($build, self::ERROR_BUILD_FAILED);
        }

        $variables = $this->variables($app);
        foreach (\array_keys($variables) as $key) {
            if (!VariableKey::isEnvVarName((string) $key)) {
                return $this->fail($build, self::ERROR_VARIABLE_INVALID, 'Variable key ' . \json_encode((string) $key) . ' is not a valid environment variable name. Update or delete this variable, then start a new build.');
            }
        }

        try {
            $owner = (string) $build->getAttribute('providerRepositoryOwner', '');
            $repository = (string) $build->getAttribute('providerRepositoryName', '');
            $source = [
                'url' => $vcs->getRepositoryPresignedUrl($owner, $repository, (string) $build->getAttribute('providerCommitHash', '')),
                'headers' => $vcs->getRepositoryPresignedUrlHeaders(),
            ];
        } catch (\Throwable) {
            return $this->fail($build, self::ERROR_BUILD_SOURCE_FAILED);
        }

        $timeout = (int) $build->getAttribute('timeout', 0) ?: $this->timeout($target);
        $payload = static::payload($this->project, $app, $target, $build, $this->platform, $timeout, $source, $variables, $this->storage($build, self::STAGE_BUILD));

        $resubmitted = $build->getAttribute('jobId', '') === $payload['id'] && !empty($build->getAttribute('dispatchedAt'));
        $build = $this->dbForProject->updateDocument('distributionBuilds', $build->getId(), new Document([
            'stage' => self::STAGE_BUILD,
            'jobId' => $payload['id'],
            'runner' => Runner::Orchestrator->value,
            ...($resubmitted ? [] : ['dispatchedAt' => DateTime::now()]),
            'toolchain' => [
                ...($build->getAttribute('toolchain') ?? []),
                'image' => self::image(),
                'cache' => $this->cached($build),
            ],
        ]));
        (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $build, 1);

        try {
            $this->jobs->create(...$payload);
        } catch (ApiException $error) {
            if ($error->statusCode !== 409) {
                $this->fail($build, self::ERROR_BUILD_FAILED, self::INTERNAL);

                throw $error;
            }
        } catch (TimeoutException) {
            return $build;
        } catch (\Throwable $error) {
            $this->fail($build, self::ERROR_BUILD_FAILED, self::INTERNAL);

            throw $error;
        }

        $this->dbForProject->updateDocuments('distributionBuilds', new Document([
            'status' => BuildStatus::Processing->value,
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', [BuildStatus::Waiting->value]),
        ]);

        return $this->dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * Returns the build unchanged once its attempts are spent, for the caller to conclude.
     */
    public function redispatch(Document $build, Git $vcs): Document
    {
        $build = $this->dbForProject->getDocument('distributionBuilds', $build->getId());
        if ($build->isEmpty() || (int) $build->getAttribute('attempt', 1) >= self::MAX_ATTEMPTS) {
            return $build;
        }

        $updated = $this->dbForProject->updateDocuments('distributionBuilds', new Document([
            'attempt' => (int) $build->getAttribute('attempt', 1) + 1,
            'status' => BuildStatus::Waiting->value,
            'stage' => '',
            'jobId' => '',
            'startedAt' => null,
            'endedAt' => null,
            'duration' => null,
            'redispatchAt' => null,
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', self::ACTIVE),
        ]);

        if ($updated === 0) {
            return $this->dbForProject->getDocument('distributionBuilds', $build->getId());
        }

        return $this->dispatch($build, $vcs);
    }

    /**
     * The schedule stays active, so reconcile settles the build and removes its outputs once the job is gone.
     */
    public function abort(Document $build): Document
    {
        $build = $this->dbForProject->getDocument('distributionBuilds', $build->getId());
        if ($build->isEmpty()) {
            return $build;
        }

        $canceled = $this->dbForProject->updateDocuments('distributionBuilds', new Document([
            'status' => BuildStatus::Canceled->value,
            'errorCode' => self::ERROR_BUILD_CANCELED,
            'errorMessage' => self::message(self::ERROR_BUILD_CANCELED),
            'endedAt' => DateTime::now(),
            'duration' => $build->getAttribute('duration') ?? self::runtime($build),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', self::ACTIVE),
        ]);

        if ($canceled === 0) {
            return $this->dbForProject->getDocument('distributionBuilds', $build->getId());
        }

        try {
            $this->halt($build);
        } catch (\Throwable) {
            // The build is canceled either way: a job that could not be deleted runs to its deadline, and reconcile waits it out.
        }

        (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $build, 1);
        $this->publisher->enqueue(new DistributionBuildMessage($this->project, DistributionBuildAction::Reconcile, $build->getId()));

        return $this->dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * The commit check of a build. False only when the provider refused the report.
     */
    public function report(Document $app, Document $build, Git $vcs): bool
    {
        $commit = (string) $build->getAttribute('providerCommitHash', '');
        if ($commit === '' || $app->getAttribute('providerSilentMode', false) === true || $app->getAttribute('providerStatuses', true) === false) {
            return true;
        }

        $projectId = $this->project->getId();
        $status = (string) $build->getAttribute('status', '');

        try {
            $vcs->updateCheck(
                (string) $build->getAttribute('providerRepositoryOwner', ''),
                (string) $build->getAttribute('providerRepositoryName', ''),
                $commit,
                self::state($status),
                (string) $app->getAttribute('name', ''),
                self::title($status),
                self::summary($build),
                ($this->platform['consoleUrl'] ?? '') . "/projects/{$projectId}/distribution/{$app->getId()}/builds/{$build->getId()}",
                suite: (string) $this->project->getAttribute('name', ''),
                key: "{$projectId}/distributionApps/{$app->getId()}/{$build->getAttribute('platform', '')}",
                suiteKey: $projectId,
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Anything not `processing` is returned as it is, so a redelivered message submits nothing.
     */
    public function inspect(Document $artifact): Document
    {
        $artifact = $this->dbForProject->getDocument('distributionArtifacts', $artifact->getId());
        if ($artifact->isEmpty() || $artifact->getAttribute('status') !== ArtifactStatus::Processing->value) {
            return $artifact;
        }

        $attempt = 1;
        $payload = static::inspection($this->project, $artifact, $this->platform, $this->storage($artifact, self::STAGE_INSPECT));
        $artifact = $this->record($artifact, [
            'stage' => self::STAGE_INSPECT,
            'id' => $payload['id'],
            'attempt' => $attempt,
            'dispatchedAt' => DateTime::now(),
        ]);

        $this->submit($this->signingJobs, $artifact, $payload, fn () => null);

        return $artifact;
    }

    /**
     * The identity is recorded in `metadata.job`, so the signing route serves
     * exactly the identity this job was submitted for. The remote bundle's key is
     * recorded before the object is written, so whatever spends the attempt can
     * always find the object.
     */
    public function sign(Document $artifact, Document $target, Document $signingIdentity): Document
    {
        $artifact = $this->dbForProject->getDocument('distributionArtifacts', $artifact->getId());
        if ($artifact->isEmpty() || $artifact->getAttribute('status') !== ArtifactStatus::Processing->value) {
            return $artifact;
        }

        $attempt = 1;
        $device = static::device($this->project->getId());
        $local = $device->getType() === DeviceType::Local;
        $bundle = $local ? '' : "signing/{$artifact->getId()}/{$attempt}-" . \bin2hex(\random_bytes(self::SIGNING_KEY_BYTES)) . '.json';
        $download = $local
            ? static::route($this->project, $artifact, $this->platform, (new Signing($this->cache))->issue($this->project, $artifact->getAttribute('appId', ''), $artifact->getId(), $attempt))
            : new DownloadArtifact(id: self::SIGNING_ARTIFACT, in: static::objectUrl($device, $device->getPath($bundle)), out: self::SIGNING_BUNDLE, timeoutSeconds: self::SIGNING_TIMEOUT);

        $payload = static::signing($this->project, $artifact, $target, $this->platform, $this->storage($artifact, self::STAGE_SIGN), $download);
        $artifact = $this->record($artifact, [
            'stage' => self::STAGE_SIGN,
            'id' => $payload['id'],
            'attempt' => $attempt,
            'dispatchedAt' => DateTime::now(),
            'signingIdentityId' => $signingIdentity->getId(),
            ...($local ? [] : ['bundle' => $bundle]),
        ]);
        $revoke = fn () => $this->revoke($artifact, $attempt);

        if (!$local) {
            try {
                $device->write($device->getPath($bundle), new Stream(\json_encode(Signing::bundle($signingIdentity), JSON_THROW_ON_ERROR)), 'application/json');
            } catch (\Throwable $error) {
                $this->abandon($artifact, new Schedules($this->dbForPlatform), $revoke);

                throw $error;
            }
        }

        $this->submit($this->signingJobs, $artifact, $payload, $revoke);

        return $artifact;
    }

    /**
     * Best effort: the caller has already made the subject terminal. A 404 is a
     * job never created, expired or already deleted.
     */
    public function cancel(Document $subject): void
    {
        [$stage, $jobId, $attempt] = $this->current($subject);

        try {
            if ($subject->getCollection() === 'distributionBuilds' && $subject->getAttribute('runner') === Runner::Pool->value) {
                $this->dbForPlatform->getAuthorization()->skip(fn () => $this->dbForPlatform->updateDocuments('distributionLeases', new Document([
                    'status' => LeaseStatus::Released->value,
                ]), [
                    Query::equal('projectInternalId', [(string) $this->project->getSequence()]),
                    Query::equal('buildId', [$subject->getId()]),
                    Query::notEqual('status', LeaseStatus::Released->value),
                ]));
                $this->dbForProject->updateDocuments('distributionArtifacts', new Document([
                    'status' => ArtifactStatus::Invalid->value,
                    'errorCode' => self::ERROR_BUILD_FAILED,
                    'errorMessage' => self::message(self::ERROR_BUILD_FAILED),
                ]), [
                    Query::equal('$id', [Leases::artifact($jobId)]),
                    Query::equal('status', [ArtifactStatus::Uploading->value, ArtifactStatus::Processing->value]),
                ]);
            } elseif ($stage !== '') {
                try {
                    ($stage === self::STAGE_BUILD ? $this->jobs : $this->signingJobs)->delete($jobId);
                } catch (ApiException $error) {
                    if ($error->statusCode !== 404) {
                        throw $error;
                    }
                }
            }
        } finally {
            $this->revoke($subject, $attempt);
        }
    }

    /**
     * What a runner that just claimed the lease needs to build its attempt, or null when the
     * lease is no longer the build's current attempt and should be released. Claiming moves
     * a waiting build to processing.
     *
     * @return array<string, mixed>|null
     */
    public function claim(Document $lease, #[\SensitiveParameter] string $token): ?array
    {
        $build = $this->dbForProject->getDocument('distributionBuilds', (string) $lease->getAttribute('buildId', ''));
        if (
            $build->isEmpty()
            || !\in_array($build->getAttribute('status'), self::ACTIVE, true)
            || $build->getAttribute('stage') !== self::STAGE_BUILD
            || $build->getAttribute('jobId') !== $lease->getId()
            || (int) $build->getAttribute('attempt', 1) !== (int) $lease->getAttribute('attempt', 1)
        ) {
            return null;
        }

        $app = $this->dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        $target = $this->dbForProject->getDocument('distributionTargets', (string) $build->getAttribute('targetId', ''));
        if ($app->isEmpty() || $target->isEmpty()) {
            return null;
        }

        $signingIdentity = $this->dbForProject->getDocument('distributionSigningIdentities', (string) $target->getAttribute('signingIdentityId', ''));
        if ($signingIdentity->isEmpty() || $signingIdentity->getAttribute('platform') !== Platform::Ios->value) {
            return null;
        }

        $variables = $this->variables($app);
        $timeout = (int) $build->getAttribute('timeout', 0) ?: $this->timeout($target);

        $this->dbForProject->updateDocuments('distributionBuilds', new Document([
            'status' => BuildStatus::Processing->value,
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', [BuildStatus::Waiting->value]),
        ]);

        Span::add('signing_identity.decrypt', $signingIdentity->getId());

        return static::assignment($this->project, $app, $target, $build, $lease, $signingIdentity, $variables, $token, $timeout);
    }

    /**
     * Lowercase and 45 characters, which every orchestrator backend accepts as a job and pod name.
     */
    public static function id(Document $project, Document $subject, string $stage, int $attempt = 1): string
    {
        return self::JOB . \substr(\hash('sha256', "{$project->getSequence()}:{$subject->getId()}:{$stage}:{$attempt}"), 0, 40);
    }

    public static function lock(string $projectInternalId, string $subjectId): string
    {
        return "distribution:build:{$projectInternalId}:{$subjectId}";
    }

    public static function path(string $artifactId, string $type): string
    {
        return "{$artifactId}.{$type}";
    }

    public static function output(string $buildId): string
    {
        return "builds/{$buildId}";
    }

    public static function cache(Document $project, string $targetId, string $image): string
    {
        return 'cache/' . \substr(\hash('sha256', "{$project->getId()}:{$targetId}:{$image}"), 0, 48) . '/cache.sqfs';
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? '';
    }

    public static function runtime(Document $build): int
    {
        $startedAt = (string) ($build->getAttribute('startedAt') ?? '');
        if ($startedAt === '') {
            return 0;
        }

        try {
            $elapsed = \max(0, \time() - (new \DateTimeImmutable($startedAt))->getTimestamp());
        } catch (\Exception) {
            return 0;
        }

        $timeout = (int) $build->getAttribute('timeout', 0);

        return $timeout > 0 ? \min($elapsed, $timeout) : $elapsed;
    }

    public static function error(string $stage, int $exitCode): ?string
    {
        if ($exitCode === 0) {
            return null;
        }

        return match ($stage) {
            self::STAGE_INSPECT => match ($exitCode) {
                2 => self::ERROR_ARTIFACT_MALFORMED,
                3 => self::ERROR_ARTIFACT_INVALID,
                8 => self::ERROR_JOB_TIMEOUT,
                default => self::ERROR_JOB_FAILED,
            },
            self::STAGE_SIGN => match ($exitCode) {
                3 => self::ERROR_SIGNING_INPUT_INVALID,
                4 => self::ERROR_ARTIFACT_ALREADY_SIGNED,
                5 => self::ERROR_SIGNING_FAILED,
                6 => self::ERROR_SIGNING_VERIFICATION_FAILED,
                7 => self::ERROR_SIGNING_BUNDLE_INVALID,
                8 => self::ERROR_JOB_TIMEOUT,
                default => self::ERROR_JOB_FAILED,
            },
            self::STAGE_BUILD => match ($exitCode) {
                2 => self::ERROR_BUILD_INVALID,
                3 => self::ERROR_BUILD_ALREADY_SIGNED,
                4 => self::ERROR_BUILD_KEYSTORE_REQUIRED,
                default => self::ERROR_BUILD_FAILED,
            },
            default => self::ERROR_JOB_FAILED,
        };
    }

    /**
     * The signing plane's key never equals the build plane's, which would let the
     * untrusted build plane forge inspect and sign results. An insecure jobs
     * secret is returned as is, so verification still fails closed on it.
     */
    public static function secret(string $stage): string
    {
        [$secret, $plane] = match ($stage) {
            self::STAGE_INSPECT, self::STAGE_SIGN => [System::getEnv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET', ''), self::PLANE_SIGNING],
            default => [System::getEnv('_APP_DISTRIBUTION_CALLBACK_SECRET', ''), self::PLANE_BUILD],
        };

        if ($secret !== '') {
            return $secret;
        }

        $jobsSecret = System::getEnv('_APP_JOBS_SECRET', '');
        if (JobsSecret::isInsecure($jobsSecret)) {
            return $jobsSecret;
        }

        return \hash_hmac('sha256', 'distribution:' . $plane, $jobsSecret);
    }

    public static function stale(int $time, int $now): bool
    {
        return \abs($now - $time) > self::TOLERANCE;
    }

    protected function admit(Document $app, Document $build): void
    {
    }

    /**
     * Queues the attempt for a macOS runner with the labels of the target. The
     * build stays `waiting` until a runner claims the lease. The build names the lease
     * before the lease row exists, so a runner can never claim a lease its build does not
     * name yet; a row lost between the two writes is queued again when the build reconciles.
     */
    protected function lease(Document $build): Document
    {
        $app = $this->dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        $target = $this->dbForProject->getDocument('distributionTargets', (string) $build->getAttribute('targetId', ''));
        if ($app->isEmpty() || $target->isEmpty()) {
            return $this->fail($build, self::ERROR_BUILD_FAILED);
        }

        $labels = Leases::labels($target);
        if (!(new Runners($this->dbForPlatform))->isAvailable(RunnerPlatform::Macos, $labels, \time())) {
            return $this->fail($build, self::ERROR_RUNNER_UNAVAILABLE);
        }

        foreach (\array_keys($this->variables($app)) as $key) {
            if (!VariableKey::isEnvVarName((string) $key)) {
                return $this->fail($build, self::ERROR_VARIABLE_INVALID, 'Variable key ' . \json_encode((string) $key) . ' is not a valid environment variable name. Update or delete this variable, then start a new build.');
            }
        }

        $identifier = (string) $target->getAttribute('identifier', '');
        $signingIdentityId = (string) $target->getAttribute('signingIdentityId', '');
        $signingIdentity = $signingIdentityId === '' ? new Document() : $this->dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);
        $profile = ($signingIdentity->getAttribute('secret') ?? [])['profiles'][$identifier] ?? '';
        if ($signingIdentity->isEmpty()
            || $signingIdentity->getAttribute('platform') !== Platform::Ios->value
            || !\is_string($profile)
            || $profile === '') {
            return $this->fail($build, self::ERROR_BUILD_INVALID, $signingIdentityId === ''
                ? "The target has no signing identity. Set an iOS signing identity with an App Store profile for {$identifier} on the target, then start a new build."
                : "Signing identity '{$signingIdentityId}' is not an iOS signing identity with an App Store profile for {$identifier}. Add the profile or choose another signing identity, then start a new build.");
        }

        if ((string) $build->getAttribute('versionName', '') === '') {
            return $this->fail($build, self::ERROR_BUILD_INVALID, "iOS builds need a version name until the runner agent supports keeping the project's version; set versionName on the build.");
        }

        $jobId = Leases::id($this->project, $build, (int) $build->getAttribute('attempt', 1));
        if ($build->getAttribute('jobId', '') !== $jobId) {
            $build = $this->dbForProject->updateDocument('distributionBuilds', $build->getId(), new Document([
                'stage' => self::STAGE_BUILD,
                'jobId' => $jobId,
                'runner' => Runner::Pool->value,
                'dispatchedAt' => DateTime::now(),
            ]));
            (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $build, 1);
        }

        (new Leases($this->dbForPlatform))->queue($this->project, $build, $labels, static::api());

        return $build;
    }

    /**
     * @return array<string, string>
     */
    protected static function query(Document $project): array
    {
        return ['project' => $project->getId()];
    }

    /**
     * On a local device `signed/{artifactId}` is created here because a sign job runs as another user.
     *
     * @return array{volumes: list<Volume>, artifacts: list<DownloadArtifact|UploadArtifact>, environment: array<string, string>}
     */
    protected function storage(Document $subject, string $stage): array
    {
        if ($stage === self::STAGE_BUILD) {
            return $this->outputs($subject);
        }

        $projectId = $this->project->getId();
        $artifactId = $subject->getId();
        $file = static::path($artifactId, $subject->getAttribute('type', ''));
        $signed = "signed/{$artifactId}";
        $device = static::device($projectId);

        if ($stage === self::STAGE_SIGN && $device instanceof Local) {
            $device->createDirectory($device->getPath($signed));
        }

        if ($device->getType() === DeviceType::Local) {
            return [
                'volumes' => [
                    new Volume(
                        source: System::getEnv('_APP_DISTRIBUTION_VOLUME', 'appwrite-distribution'),
                        path: self::STORAGE,
                        subPath: "app-{$projectId}",
                        readonly: $stage === self::STAGE_INSPECT,
                    ),
                ],
                'artifacts' => [],
                'environment' => [
                    'DISTRIBUTION_INPUT' => self::STORAGE . "/{$file}",
                    ...($stage === self::STAGE_SIGN ? ['DISTRIBUTION_OUTPUT_DIR' => self::STORAGE . "/{$signed}"] : []),
                ],
            ];
        }

        $input = static::objectUrl($device, $device->getPath($file));

        return [
            'volumes' => [],
            'artifacts' => match ($stage) {
                self::STAGE_SIGN => [
                    new DownloadArtifact(id: 'input', in: $input, out: 'input/app-unsigned.aab', timeoutSeconds: self::TRANSFER_TIMEOUT),
                    new UploadArtifact(id: 'output', in: 'output/app.aab', out: static::objectUrl($device, $device->getPath("{$signed}/app.aab")), depends: 'job', timeoutSeconds: self::TRANSFER_TIMEOUT),
                    new UploadArtifact(id: 'resultCopy', in: 'output/result.json', out: static::objectUrl($device, $device->getPath("{$signed}/result.json")), depends: 'job', timeoutSeconds: self::SIGNING_TIMEOUT),
                ],
                default => [
                    new DownloadArtifact(id: 'input', in: $input, out: 'input/artifact', timeoutSeconds: self::TRANSFER_TIMEOUT),
                ],
            },
            'environment' => [],
        ];
    }

    protected function timeout(Document $target): int
    {
        return (int) $target->getAttribute('timeout', 0) ?: (int) System::getEnv('_APP_DISTRIBUTION_BUILD_TIMEOUT', (string) self::BUILD_TIMEOUT);
    }

    /**
     * The public endpoint a runner calls back on, unlike the internal one of build jobs.
     */
    protected static function api(): string
    {
        return (System::getEnv('_APP_OPTIONS_FORCE_HTTPS', '') === 'disabled' ? 'http' : 'https') . '://' . System::getEnv('_APP_DOMAIN', 'localhost') . '/v1';
    }

    /**
     * @param array{volumes: list<Volume>, artifacts: list<DownloadArtifact|UploadArtifact>, environment: array<string, string>} $storage
     * @return array<string, mixed> Named arguments for OpenRuntimes\Orchestrator\Jobs::create().
     */
    protected static function inspection(Document $project, Document $artifact, array $platform, array $storage): array
    {
        return self::job($project, $artifact, self::STAGE_INSPECT, $platform, self::INSPECT_COMMAND, [
            'DISTRIBUTION_ARTIFACT_TYPE' => $artifact->getAttribute('type', ''),
            ...$storage['environment'],
        ], $storage, []);
    }

    /**
     * @param array{volumes: list<Volume>, artifacts: list<DownloadArtifact|UploadArtifact>, environment: array<string, string>} $storage
     * @return array<string, mixed> Named arguments for OpenRuntimes\Orchestrator\Jobs::create().
     */
    protected static function signing(Document $project, Document $artifact, Document $target, array $platform, array $storage, DownloadArtifact $bundle): array
    {
        return self::job($project, $artifact, self::STAGE_SIGN, $platform, self::SIGN_COMMAND, [
            'DISTRIBUTION_IDENTIFIER' => $target->getAttribute('identifier', ''),
            'DISTRIBUTION_VERSION_CODE' => (string) $artifact->getAttribute('versionCode', ''),
            ...$storage['environment'],
        ], $storage, [$bundle]);
    }

    /**
     * The build plane is untrusted: no Appwrite API key, signing material or store credential enters the job.
     *
     * @param array{url: string, headers: array<string, string>} $source
     * @param array<string, string> $variables
     * @param array{volumes: list<Volume>, artifacts: list<DownloadArtifact|UploadArtifact>, environment: array<string, string>} $storage
     * @return array<string, mixed> Named arguments for OpenRuntimes\Orchestrator\Jobs::create().
     */
    protected static function payload(Document $project, Document $app, Document $target, Document $build, array $platform, int $timeout, array $source, array $variables, array $storage): array
    {
        $attempt = (int) $build->getAttribute('attempt', 1);
        $framework = (string) $app->getAttribute('framework', '');
        $defaults = Config::getParam('distribution')['frameworks'][$framework][(string) $target->getAttribute('platform', '')] ?? [];

        return [
            'id' => static::id($project, $build, self::STAGE_BUILD, $attempt),
            'image' => self::image(),
            'command' => self::BUILD_COMMAND,
            'cpu' => (float) $build->getAttribute('cpus', 0),
            'memory' => (int) $build->getAttribute('memory', 0),
            'timeoutSeconds' => $timeout + self::SETUP,
            'workspace' => self::WORKSPACE,
            'meta' => [
                'projectId' => $project->getId(),
                'appId' => $app->getId(),
                'buildId' => $build->getId(),
                'stage' => self::STAGE_BUILD,
                'attempt' => (string) $attempt,
            ],
            'environment' => \array_map('strval', \array_replace($variables, [
                'DISTRIBUTION_PLATFORM' => (string) $target->getAttribute('platform', ''),
                'DISTRIBUTION_FRAMEWORK' => $framework,
                'DISTRIBUTION_ROOT_DIRECTORY' => (string) $build->getAttribute('providerRootDirectory', '') ?: '.',
                'DISTRIBUTION_INSTALL_COMMAND' => (string) $target->getAttribute('installCommand', '') ?: ($defaults['install'] ?? ''),
                'DISTRIBUTION_BUILD_COMMAND' => (string) $target->getAttribute('buildCommand', '') ?: ($defaults['build'] ?? ''),
                'DISTRIBUTION_OUTPUT_PATH' => (string) $target->getAttribute('outputPath', '') ?: ($defaults['output'] ?? ''),
                'DISTRIBUTION_CONFIGURATION' => \json_encode($target->getAttribute('configuration') ?: new \stdClass(), JSON_THROW_ON_ERROR),
                'DISTRIBUTION_IDENTIFIER' => (string) $target->getAttribute('identifier', ''),
                'DISTRIBUTION_VERSION_NAME' => (string) $build->getAttribute('versionName', ''),
                'DISTRIBUTION_VERSION_CODE' => (string) ($build->getAttribute('versionCode') ?? ''),
                'DISTRIBUTION_BUILD_ID' => $build->getId(),
                ...$storage['environment'],
                'APPWRITE_VCS_REPOSITORY_ID' => (string) $app->getAttribute('providerRepositoryId', ''),
                'APPWRITE_VCS_REPOSITORY_NAME' => (string) $build->getAttribute('providerRepositoryName', ''),
                'APPWRITE_VCS_REPOSITORY_OWNER' => (string) $build->getAttribute('providerRepositoryOwner', ''),
                'APPWRITE_VCS_REPOSITORY_URL' => (string) $build->getAttribute('providerRepositoryUrl', ''),
                'APPWRITE_VCS_REPOSITORY_BRANCH' => (string) $build->getAttribute('providerBranch', ''),
                'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => (string) $build->getAttribute('providerBranchUrl', ''),
                'APPWRITE_VCS_COMMIT_HASH' => (string) $build->getAttribute('providerCommitHash', ''),
                'APPWRITE_VCS_COMMIT_MESSAGE' => (string) $build->getAttribute('providerCommitMessage', ''),
                'APPWRITE_VCS_COMMIT_URL' => (string) $build->getAttribute('providerCommitUrl', ''),
                'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => (string) $build->getAttribute('providerCommitAuthor', ''),
                'APPWRITE_VCS_COMMIT_AUTHOR_URL' => (string) $build->getAttribute('providerCommitAuthorUrl', ''),
                'APPWRITE_VCS_ROOT_DIRECTORY' => (string) $build->getAttribute('providerRootDirectory', ''),
                'CI' => 'true',
            ])),
            'artifacts' => [
                new DownloadArtifact(id: 'source', in: $source['url'], out: 'source.tar.gz', timeoutSeconds: self::TRANSFER_TIMEOUT, headers: $source['headers']),
                new UnarchiveArtifact(id: 'extract', in: 'source.tar.gz', out: 'source', strip: true, depends: 'source'),
                ...$storage['artifacts'],
                new ReadArtifact(id: 'result', in: 'output/result.json', format: ReadFormat::Json, depends: 'job'),
            ],
            'volumes' => $storage['volumes'],
            'callback' => new Callback(
                url: self::endpoint($platform) . '/v1/distribution/events?' . \http_build_query(static::query($project)),
                events: [CallbackEvent::Start, CallbackEvent::Log, CallbackEvent::Exit, CallbackEvent::Complete, CallbackEvent::Artifact],
                key: self::secret(self::STAGE_BUILD),
            ),
        ];
    }

    /**
     * The lease a runner agent builds from. Unlike a build-plane job, a runner is trusted with
     * the signing identity, so the lease carries it. The version name is sent as stored.
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    protected static function assignment(Document $project, Document $app, Document $target, Document $build, Document $lease, Document $signingIdentity, array $variables, #[\SensitiveParameter] string $token, int $timeout): array
    {
        $framework = (string) $app->getAttribute('framework', '');
        $defaults = Config::getParam('distribution')['frameworks'][$framework][Platform::Ios->value] ?? [];
        $configuration = $target->getAttribute('configuration');
        $configuration = \is_array($configuration) ? $configuration : [];
        $scheme = $configuration['scheme'] ?? null;
        $secret = $signingIdentity->getAttribute('secret', []);
        $secret = \is_array($secret) ? $secret : [];
        $profiles = \is_array($secret['profiles'] ?? null) ? $secret['profiles'] : [];

        return [
            'leaseId' => $lease->getId(),
            'token' => $token,
            'expiresAt' => DateTime::formatTz((string) $lease->getAttribute('expiresAt', '') ?: DateTime::now()),
            'endpoint' => (string) $lease->getAttribute('endpoint', ''),
            'query' => static::query($project),
            'buildId' => $build->getId(),
            'attempt' => (int) $build->getAttribute('attempt', 1),
            'timeout' => $timeout,
            'platform' => Platform::Ios->value,
            'framework' => $framework,
            'source' => [
                'rootDirectory' => (string) $build->getAttribute('providerRootDirectory', '') ?: '.',
                'commit' => (string) $build->getAttribute('providerCommitHash', ''),
            ],
            'environment' => \array_map('strval', \array_replace($variables, [
                'DISTRIBUTION_IDENTIFIER' => (string) $target->getAttribute('identifier', ''),
                'DISTRIBUTION_CONFIGURATION' => \json_encode($configuration ?: new \stdClass(), JSON_THROW_ON_ERROR),
                ...(\is_string($scheme) && $scheme !== '' ? ['DISTRIBUTION_SCHEME' => $scheme] : []),
                'APPWRITE_VCS_REPOSITORY_ID' => (string) $app->getAttribute('providerRepositoryId', ''),
                'APPWRITE_VCS_REPOSITORY_NAME' => (string) $build->getAttribute('providerRepositoryName', ''),
                'APPWRITE_VCS_REPOSITORY_OWNER' => (string) $build->getAttribute('providerRepositoryOwner', ''),
                'APPWRITE_VCS_REPOSITORY_URL' => (string) $build->getAttribute('providerRepositoryUrl', ''),
                'APPWRITE_VCS_REPOSITORY_BRANCH' => (string) $build->getAttribute('providerBranch', ''),
                'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => (string) $build->getAttribute('providerBranchUrl', ''),
                'APPWRITE_VCS_COMMIT_HASH' => (string) $build->getAttribute('providerCommitHash', ''),
                'APPWRITE_VCS_COMMIT_MESSAGE' => (string) $build->getAttribute('providerCommitMessage', ''),
                'APPWRITE_VCS_COMMIT_URL' => (string) $build->getAttribute('providerCommitUrl', ''),
                'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => (string) $build->getAttribute('providerCommitAuthor', ''),
                'APPWRITE_VCS_COMMIT_AUTHOR_URL' => (string) $build->getAttribute('providerCommitAuthorUrl', ''),
                'APPWRITE_VCS_ROOT_DIRECTORY' => (string) $build->getAttribute('providerRootDirectory', ''),
                'CI' => 'true',
            ])),
            'commands' => [
                'install' => (string) $target->getAttribute('installCommand', '') ?: ($defaults['install'] ?? ''),
                'build' => (string) $target->getAttribute('buildCommand', '') ?: ($defaults['build'] ?? ''),
            ],
            'signing' => [
                'certificate' => (string) ($secret['certificate'] ?? ''),
                'password' => (string) ($secret['password'] ?? ''),
                'profiles' => \array_filter(
                    $profiles,
                    fn (mixed $profile, int|string $identifier): bool => \is_string($profile)
                        && \is_string($identifier)
                        && $identifier !== ''
                        && !\str_starts_with($identifier, '.')
                        && \strspn($identifier, self::BUNDLE) === \strlen($identifier),
                    ARRAY_FILTER_USE_BOTH,
                ),
                'teamId' => (string) $signingIdentity->getAttribute('teamId', ''),
            ],
            'version' => [
                'name' => (string) $build->getAttribute('versionName', ''),
                'code' => (int) $build->getAttribute('buildNumber', 0),
            ],
        ];
    }

    /**
     * The local device's bundle download: the signing route, authorized by a single-use token.
     */
    protected static function route(Document $project, Document $artifact, array $platform, string $token): DownloadArtifact
    {
        $appId = $artifact->getAttribute('appId', '');

        return new DownloadArtifact(
            id: self::SIGNING_ARTIFACT,
            in: self::endpoint($platform) . "/v1/distribution/apps/{$appId}/builds/{$artifact->getId()}/signing?" . \http_build_query(static::query($project)),
            out: self::SIGNING_BUNDLE,
            timeoutSeconds: self::SIGNING_TIMEOUT,
            headers: ['authorization' => 'Bearer ' . $token],
        );
    }

    protected static function device(string $projectId): Device
    {
        return getDevice(APP_STORAGE_DISTRIBUTION . "/app-{$projectId}");
    }

    /**
     * Resolves the bucket the way getDevice() does, as Deployments::objectUrl() does.
     */
    protected static function objectUrl(Device $device, string $path): string
    {
        $type = $device->getType();
        $configuredDevice = DeviceType::tryFrom(\strtolower(System::getEnv('_APP_STORAGE_DEVICE', DeviceType::Local->value))) ?? DeviceType::Local;
        $s3AccessKey = System::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
        $s3AccessSecret = System::getEnv('_APP_STORAGE_S3_SECRET', '');
        $s3Bucket = System::getEnv('_APP_STORAGE_S3_BUCKET', '');
        $hasS3Configuration = $s3AccessKey !== '' && $s3AccessSecret !== '' && $s3Bucket !== '';
        $connection = System::getEnv('_APP_CONNECTIONS_STORAGE', '');
        $usesS3Configuration = $hasS3Configuration && ($connection === '' || \in_array($configuredDevice, [DeviceType::S3, DeviceType::AwsS3], true));

        if ($usesS3Configuration && \in_array($type, [DeviceType::S3, DeviceType::AwsS3], true) && System::getEnv('_APP_STORAGE_S3_ENDPOINT', '') !== '') {
            $bucket = '';
        } elseif (!$usesS3Configuration && $connection !== '') {
            $bucket = \trim((new DSN($connection))->getPath() ?? '', '/');
        } elseif ($usesS3Configuration) {
            $bucket = $s3Bucket;
        } else {
            $prefix = match ($type) {
                DeviceType::DoSpaces => 'DO_SPACES',
                DeviceType::Backblaze => 'BACKBLAZE',
                DeviceType::Linode => 'LINODE',
                DeviceType::Wasabi => 'WASABI',
                DeviceType::S3, DeviceType::AwsS3, DeviceType::Local => null,
            };
            $bucket = $prefix === null ? '' : System::getEnv("_APP_STORAGE_{$prefix}_BUCKET", '');
        }

        return 's3://' . \ltrim(($bucket !== '' ? "/{$bucket}" : '') . $path, '/');
    }

    /**
     * @param array<string, string> $environment
     * @param array{volumes: list<Volume>, artifacts: list<DownloadArtifact|UploadArtifact>, environment: array<string, string>} $storage
     * @param list<DownloadArtifact> $inputs Pre-job downloads besides the storage ones
     * @return array<string, mixed>
     */
    private static function job(Document $project, Document $artifact, string $stage, array $platform, string $command, array $environment, array $storage, array $inputs): array
    {
        $attempt = 1;

        return [
            'id' => static::id($project, $artifact, $stage, $attempt),
            'image' => Config::getParam('distribution')['images']['tools'],
            'command' => $command,
            'cpu' => self::CPU,
            'memory' => self::MEMORY,
            'timeoutSeconds' => self::TIMEOUT,
            'workspace' => self::WORKSPACE,
            'meta' => [
                'projectId' => $project->getId(),
                'appId' => (string) $artifact->getAttribute('appId', ''),
                'artifactId' => $artifact->getId(),
                'stage' => $stage,
                'attempt' => (string) $attempt,
            ],
            'environment' => \array_map('strval', $environment),
            'artifacts' => [
                ...$inputs,
                ...$storage['artifacts'],
                new ReadArtifact(id: 'result', in: 'output/result.json', format: ReadFormat::Json, depends: 'job'),
            ],
            'volumes' => $storage['volumes'],
            'callback' => new Callback(
                url: self::endpoint($platform) . '/v1/distribution/events?' . \http_build_query(static::query($project)),
                events: [CallbackEvent::Start, CallbackEvent::Exit, CallbackEvent::Complete, CallbackEvent::Artifact],
                key: self::secret($stage),
            ),
        ];
    }

    /**
     * Plain HTTP on the internal network, as Deployments::payload() explains.
     */
    private static function endpoint(array $platform): string
    {
        return System::getEnv('_APP_JOBS_ENDPOINT', '') ?: "http://{$platform['apiHostname']}";
    }

    /**
     * @param array<string, mixed> $job
     */
    private function record(Document $artifact, array $job): Document
    {
        return $this->dbForProject->updateDocument('distributionArtifacts', $artifact->getId(), new Document([
            'metadata' => [
                ...$artifact->getAttribute('metadata', []),
                'job' => $job,
            ],
        ]));
    }

    /**
     * A 409 is this job already submitted, and a client timeout may still be one
     * being created (an image pull on Docker), so both are left to the callbacks
     * and reconcile.
     *
     * @param array<string, mixed> $payload
     * @param callable(): void $revoke
     */
    private function submit(Jobs $jobs, Document $artifact, array $payload, callable $revoke): void
    {
        $schedules = new Schedules($this->dbForPlatform);
        $schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $artifact, 1);

        try {
            $jobs->create(...$payload);
        } catch (ApiException $error) {
            if ($error->statusCode === 409) {
                return;
            }

            $this->abandon($artifact, $schedules, $revoke);

            throw $error;
        } catch (TimeoutException) {
            return;
        } catch (\Throwable $error) {
            $this->abandon($artifact, $schedules, $revoke);

            throw $error;
        }
    }

    /**
     * @param callable(): void $revoke
     */
    private function abandon(Document $artifact, Schedules $schedules, callable $revoke): void
    {
        $revoke();

        $this->dbForProject->updateDocuments('distributionArtifacts', new Document([
            'status' => ArtifactStatus::Invalid->value,
            'errorCode' => self::ERROR_JOB_FAILED,
            'errorMessage' => 'An internal error occurred while processing the artifact. Please try again, and contact support if the problem persists.',
        ]), [
            Query::equal('$id', [$artifact->getId()]),
            Query::equal('status', [ArtifactStatus::Processing->value]),
        ]);

        $schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $artifact->getId());
    }

    /**
     * Spends the attempt's signing token and deletes its bundle object, which
     * is a storage grant that never expires by itself.
     */
    private function revoke(Document $subject, int $attempt): void
    {
        (new Signing($this->cache))->revoke($this->project, $subject->getId(), $attempt);

        $bundle = (string) ($subject->getAttribute('metadata', [])['job']['bundle'] ?? '');
        if ($bundle === '') {
            return;
        }

        $device = static::device($this->project->getId());
        $device->delete($device->getPath($bundle));
    }

    /**
     * @return array{string, string, int} Stage, job id and attempt
     */
    private function current(Document $subject): array
    {
        if ($subject->getCollection() === 'distributionBuilds') {
            $stage = (string) $subject->getAttribute('stage', '');
            $attempt = (int) $subject->getAttribute('attempt', 1);
            $stage = $stage === self::STAGE_SIGN ? self::STAGE_SIGN : ($stage === '' ? '' : self::STAGE_BUILD);

            return [$stage, $subject->getAttribute('jobId', '') ?: ($stage === '' ? '' : static::id($this->project, $subject, $stage, $attempt)), $attempt];
        }

        $job = $subject->getAttribute('metadata', [])['job'] ?? [];
        $stage = (string) ($job['stage'] ?? '');
        $attempt = (int) ($job['attempt'] ?? 1);

        return [$stage, (string) ($job['id'] ?? '') ?: ($stage === '' ? '' : static::id($this->project, $subject, $stage, $attempt)), $attempt];
    }

    /**
     * @return array{volumes: list<Volume>, artifacts: list<DownloadArtifact|UploadArtifact>, environment: array<string, string>}
     */
    private function outputs(Document $build): array
    {
        $projectId = $this->project->getId();
        $output = static::output($build->getId());
        $cache = $this->caches($build) ? static::cache($this->project, (string) $build->getAttribute('targetId', ''), self::image()) : '';
        $device = static::device($projectId);

        if ($device instanceof Local) {
            $device->createDirectory($device->getPath($output));
            if ($cache !== '') {
                $device->createDirectory($device->getPath(\dirname($cache)));
            }
        }

        if ($device->getType() === DeviceType::Local) {
            $source = System::getEnv('_APP_DISTRIBUTION_VOLUME', 'appwrite-distribution');

            return [
                'volumes' => [
                    new Volume(source: $source, path: self::OUTPUT, subPath: "app-{$projectId}/{$output}"),
                    ...($cache === '' ? [] : [new Volume(source: $source, path: self::CACHE, subPath: "app-{$projectId}/" . \dirname($cache))]),
                ],
                'artifacts' => [],
                'environment' => [
                    'DISTRIBUTION_OUTPUT_DIR' => self::OUTPUT,
                    ...($cache === '' ? [] : ['DISTRIBUTION_CACHE_ARTIFACT' => self::CACHE . '/' . \basename($cache)]),
                ],
            ];
        }

        $url = static fn (string $path): string => static::objectUrl($device, $device->getPath($path));

        return [
            'volumes' => [],
            'artifacts' => [
                ...($this->cached($build) ? [new DownloadArtifact(id: 'cachePull', in: $url($cache), out: $cache, timeoutSeconds: self::TRANSFER_TIMEOUT)] : []),
                new UploadArtifact(id: 'output', in: 'output/app-unsigned.aab', out: $url("{$output}/app-unsigned.aab"), depends: 'job', timeoutSeconds: self::TRANSFER_TIMEOUT),
                new UploadArtifact(id: 'resultCopy', in: 'output/result.json', out: $url("{$output}/result.json"), depends: 'job', timeoutSeconds: self::SIGNING_TIMEOUT),
                new UploadArtifact(id: 'mapping', in: 'output/mapping.txt', out: $url("{$output}/mapping.txt"), depends: 'job', timeoutSeconds: self::SIGNING_TIMEOUT),
                ...($cache === '' ? [] : [new UploadArtifact(id: 'cache', in: $cache, out: $url($cache), depends: 'job', timeoutSeconds: self::TRANSFER_TIMEOUT)]),
            ],
            'environment' => $cache === '' ? [] : ['DISTRIBUTION_CACHE_ARTIFACT' => $cache],
        ];
    }

    /**
     * A build whose cache pull failed is dispatched again without the cache.
     */
    private function caches(Document $build): bool
    {
        return (($build->getAttribute('toolchain') ?? [])['cacheFailed'] ?? false) !== true;
    }

    private function cached(Document $build): bool
    {
        if (!$this->caches($build)) {
            return false;
        }

        $device = static::device($this->project->getId());

        return $device->exists($device->getPath(static::cache($this->project, (string) $build->getAttribute('targetId', ''), self::image())));
    }

    private static function image(): string
    {
        return Config::getParam('distribution')['images'][Platform::Android->value];
    }

    private function allocate(Document $target, ?int $latest): int
    {
        try {
            $target = $this->dbForProject->increaseDocumentAttribute('distributionTargets', $target->getId(), 'versionCode', 1, self::MAX_VERSION_CODE);
            $code = (int) $target->getAttribute('versionCode');

            if ($latest !== null && $latest >= $code) {
                $target = $this->dbForProject->increaseDocumentAttribute('distributionTargets', $target->getId(), 'versionCode', $latest + 1 - $code, self::MAX_VERSION_CODE);
                $code = (int) $target->getAttribute('versionCode');
            }
        } catch (Limit) {
            throw new Exception(Exception::DISTRIBUTION_VERSION_INVALID, 'The next version code of the target would exceed ' . self::MAX_VERSION_CODE . ', the highest Google Play accepts.');
        }

        return $code;
    }

    private function preflight(Document $app, Document $target, Document $build, ?Checks $checks): void
    {
        if ($checks === null) {
            return;
        }

        try {
            $platform = Platform::tryFrom((string) $target->getAttribute('platform', ''));
            $track = (string) $target->getAttribute('track', '') ?: ($platform === null ? '' : Stores::testing(Stores::provider($platform)));
            $issues = $checks->run($app, $target, $track, null, store: false)['issues'];

            $size = 0;
            foreach ($issues as $chunk => $issue) {
                $content = \mb_strcut("{$issue['severity']} {$issue['code']}: {$issue['message']} {$issue['remediation']}\n", 0, Logs::CHUNK);
                $this->dbForProject->createDocument('distributionLogs', new Document([
                    'buildId' => $build->getId(),
                    'buildInternalId' => (string) $build->getSequence(),
                    'sequence' => 0,
                    'chunk' => $chunk,
                    'stage' => Stage::Source->value,
                    'content' => $content,
                ]));
                $size += \strlen($content);
            }

            if ($size > 0) {
                $this->dbForProject->increaseDocumentAttribute('distributionBuilds', $build->getId(), 'logsSize', $size);
            }
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * @return array<string, string>
     */
    private function variables(Document $app): array
    {
        $rows = [
            ...$this->dbForProject->find('variables', [
                Query::equal('resourceType', ['project']),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]),
            ...$this->dbForProject->find('variables', [
                Query::equal('resourceType', [RESOURCE_TYPE_DISTRIBUTION_APPS]),
                Query::equal('resourceInternalId', [(string) $app->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]),
        ];

        $variables = [];
        foreach ($rows as $variable) {
            $variables[(string) $variable->getAttribute('key', '')] = (string) $variable->getAttribute('value', '');
        }

        return $variables;
    }

    /**
     * Every terminal write of this class besides a cancel. The schedule row stays active,
     * because reconcile settles a terminal build, removes its outputs and then stops it.
     */
    private function fail(Document $build, string $code, string $message = ''): Document
    {
        $this->dbForProject->updateDocuments('distributionBuilds', new Document([
            'status' => BuildStatus::Failed->value,
            'errorCode' => $code,
            'errorMessage' => $message ?: self::message($code),
            'endedAt' => DateTime::now(),
            'duration' => $build->getAttribute('duration') ?? self::runtime($build),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', self::ACTIVE),
        ]);

        (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $build, 1);

        return $this->dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    private function halt(Document $build): void
    {
        $artifactId = (string) $build->getAttribute('artifactId', '');
        if ($build->getAttribute('stage') !== self::STAGE_SIGN || $artifactId === '') {
            $this->cancel($build);

            return;
        }

        $this->dbForProject->updateDocuments('distributionArtifacts', new Document([
            'status' => ArtifactStatus::Invalid->value,
            'errorCode' => self::ERROR_BUILD_CANCELED,
            'errorMessage' => self::message(self::ERROR_BUILD_CANCELED),
        ]), [
            Query::equal('$id', [$artifactId]),
            Query::equal('status', [ArtifactStatus::Processing->value]),
        ]);
        (new Schedules($this->dbForPlatform))->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $artifactId);

        $artifact = $this->dbForProject->getDocument('distributionArtifacts', $artifactId);
        if (!$artifact->isEmpty()) {
            $this->cancel($artifact);
        }
    }

    private static function state(string $status): string
    {
        return match ($status) {
            BuildStatus::Waiting->value => 'queued',
            BuildStatus::Processing->value, BuildStatus::Building->value, BuildStatus::Signing->value => 'pending',
            BuildStatus::Ready->value => 'success',
            BuildStatus::Failed->value => 'failure',
            BuildStatus::Canceled->value => 'cancelled',
            default => $status,
        };
    }

    private static function title(string $status): string
    {
        return match ($status) {
            BuildStatus::Waiting->value => 'Queued...',
            BuildStatus::Processing->value, BuildStatus::Building->value => 'Building...',
            BuildStatus::Signing->value => 'Signing...',
            BuildStatus::Ready->value => 'Build succeeded.',
            BuildStatus::Failed->value => 'Build failed.',
            BuildStatus::Canceled->value => 'Build canceled.',
            default => $status,
        };
    }

    private static function summary(Document $build): string
    {
        $reference = (string) $build->getAttribute('providerBranch', '') ?: (string) $build->getAttribute('providerTag', '');
        $message = \trim(\explode("\n", (string) $build->getAttribute('providerCommitMessage', ''))[0]);
        $lines = [
            'Commit ' . \substr((string) $build->getAttribute('providerCommitHash', ''), 0, 7) . ($reference !== '' ? " on {$reference}" : '') . ($message !== '' ? ": {$message}" : ''),
            'Platform ' . $build->getAttribute('platform', ''),
        ];

        $code = (string) ($build->getAttribute('versionCode') ?? $build->getAttribute('buildNumber', ''));
        $version = \trim($build->getAttribute('versionName', '') . ($code !== '' ? " ({$code})" : ''));
        if ($version !== '') {
            $lines[] = "Version {$version}";
        }

        $duration = (int) $build->getAttribute('duration', 0);
        if ($duration > 0) {
            $lines[] = match ($build->getAttribute('status')) {
                BuildStatus::Ready->value => "Built in {$duration}s",
                BuildStatus::Failed->value => "Failed after {$duration}s",
                BuildStatus::Canceled->value => "Canceled after {$duration}s",
                default => "Running for {$duration}s",
            };
        }

        return '- ' . \implode("\n- ", $lines);
    }
}
