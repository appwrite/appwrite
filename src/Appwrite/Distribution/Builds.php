<?php

namespace Appwrite\Distribution;

use Appwrite\Distribution\Artifact\Status as ArtifactStatus;
use Appwrite\Distribution\Build\Runner;
use Appwrite\Distribution\Lease\Status as LeaseStatus;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\ReadFormat;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Exception\TimeoutException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\ReadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UploadArtifact;
use OpenRuntimes\Orchestrator\Model\Callback;
use OpenRuntimes\Orchestrator\Model\Volume;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\DeviceType;
use Utopia\System\System;

/**
 * Signing material never enters a job specification, which stays readable until the job object is removed.
 */
readonly class Builds
{
    public const string STAGE_INSPECT = 'inspect';
    public const string STAGE_SIGN = 'sign';
    public const string STAGE_BUILD = 'build';

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

    /**
     * Wider than the orchestrator SDK's 300 s, because a healthy orchestrator can
     * deliver an event about 300 s late while its circuit breaker is open.
     */
    private const int TOLERANCE = 900;

    public const string WORKSPACE = '/mnt/code';

    public const string STORAGE = '/mnt/storage';

    private const string INSPECT_COMMAND = '/usr/local/distribution/inspect.sh';

    private const string SIGN_COMMAND = '/usr/local/distribution/sign-android.sh';

    private const string SIGNING_ARTIFACT = 'signing';

    private const string SIGNING_BUNDLE = 'signing/bundle.json';

    private const int SIGNING_KEY_BYTES = 32;

    private const int TRANSFER_ATTEMPTS = 4;

    private const float CPU = 1.0;

    private const int MEMORY = 2048;

    private const int BUILD_TIMEOUT = 3600;

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
     * Lowercase and 45 characters, which every orchestrator backend accepts as a job and pod name.
     */
    public static function id(Document $project, Document $subject, string $stage, int $attempt = 1): string
    {
        return 'dist-' . \substr(\hash('sha256', "{$project->getSequence()}:{$subject->getId()}:{$stage}:{$attempt}"), 0, 40);
    }

    public static function lock(string $projectInternalId, string $subjectId): string
    {
        return "distribution:build:{$projectInternalId}:{$subjectId}";
    }

    public static function path(string $artifactId, string $type): string
    {
        return "{$artifactId}.{$type}";
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
            default => self::ERROR_JOB_FAILED,
        };
    }

    /**
     * The signing plane's key never falls back to the build plane's, which would
     * let the untrusted build plane forge inspect and sign results.
     */
    public static function secret(string $stage): string
    {
        $secret = match ($stage) {
            self::STAGE_INSPECT, self::STAGE_SIGN => System::getEnv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET', ''),
            default => System::getEnv('_APP_DISTRIBUTION_CALLBACK_SECRET', ''),
        };

        return $secret ?: System::getEnv('_APP_JOBS_SECRET', '');
    }

    public static function stale(int $time, int $now): bool
    {
        return \abs($now - $time) > self::TOLERANCE;
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
}
