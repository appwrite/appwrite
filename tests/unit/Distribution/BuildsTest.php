<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Auth\JobsSecret;
use Appwrite\Distribution\Build\Status as BuildStatus;
use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Leases;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Signing;
use Appwrite\Distribution\Stores;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\ReadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UnarchiveArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\UploadArtifact;
use OpenRuntimes\Orchestrator\Model\Callback;
use OpenRuntimes\Orchestrator\Model\Volume;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\AgingMemory;
use Tests\Unit\Distribution\Fakes\ExposedBuilds;
use Tests\Unit\Distribution\Fakes\LocalBuilds;
use Tests\Unit\Distribution\Fakes\MemoryBuilds;
use Tests\Unit\Distribution\Fakes\MemoryDatabase;
use Tests\Unit\Distribution\Fakes\MemoryDevice;
use Tests\Unit\Distribution\Fakes\Orchestrator;
use Tests\Unit\Distribution\Fakes\RecordingBroker;
use Tests\Unit\Distribution\Fakes\RecordingGit;
use Tests\Unit\Distribution\Fakes\RemoteBuilds;
use Tests\Unit\Distribution\Fakes\ScriptedChecks;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Queue\Queue;

final class BuildsTest extends TestCase
{
    private const array SECRETS = [
        '_APP_JOBS_SECRET' => 'jobs-api-secret',
        '_APP_DISTRIBUTION_JOBS_SECRET' => 'distribution-api-secret',
        '_APP_DISTRIBUTION_SIGNING_JOBS_SECRET' => 'signing-api-secret',
        '_APP_DISTRIBUTION_CALLBACK_SECRET' => 'build-callback-secret',
        '_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET' => 'signing-callback-secret',
        '_APP_JOBS_ENDPOINT' => 'http://appwrite',
        '_APP_DISTRIBUTION_VOLUME' => 'appwrite-distribution',
    ];

    private const array DEFAULTS = [
        '_APP_DISTRIBUTION_BUILD_SPECIFICATION' => 'd-4vcpu-8gb',
        '_APP_DISTRIBUTION_BUILD_TIMEOUT' => '3600',
    ];

    private const string CREDENTIAL = 'store-credential-never-in-a-job';

    private const string LEASE = '0123456789abcdef0123456789abcdef0123';

    /**
     * Unset for every test, so objectUrl() resolves the bucket from the device alone.
     */
    private const array STORAGE = [
        '_APP_STORAGE_DEVICE',
        '_APP_STORAGE_S3_ACCESS_KEY',
        '_APP_STORAGE_S3_SECRET',
        '_APP_STORAGE_S3_BUCKET',
        '_APP_STORAGE_S3_ENDPOINT',
        '_APP_CONNECTIONS_STORAGE',
    ];

    private const array IDENTITY = [
        'keystore' => 'a2V5c3RvcmUtYnl0ZXMtdGhhdC1tdXN0LW5ldmVyLWxlYXZl',
        'storePassword' => 'store-password-never-in-a-job',
        'keyAlias' => 'upload-alias-never-in-a-job',
        'keyPassword' => 'key-password-never-in-a-job',
    ];

    /** @var array<string, string|false> */
    private array $environment = [];

    private Document $project;

    private Orchestrator $buildPlane;

    private Orchestrator $signingPlane;

    private MemoryDatabase $dbForProject;

    private MemoryDatabase $dbForPlatform;

    private Cache $cache;

    private RecordingBroker $broker;

    protected function setUp(): void
    {
        foreach ([...self::SECRETS, ...self::DEFAULTS] as $name => $value) {
            $this->environment[$name] = \getenv($name);
            \putenv("{$name}={$value}");
        }
        foreach (self::STORAGE as $name) {
            $this->environment[$name] = \getenv($name);
            \putenv($name);
        }
        MemoryDevice::reset();

        $this->project = new Document(['$id' => 'project1', '$sequence' => '7', 'region' => 'default']);
        $this->buildPlane = new Orchestrator();
        $this->signingPlane = new Orchestrator();
        $this->dbForProject = new MemoryDatabase();
        $this->dbForPlatform = new MemoryDatabase();
        $this->cache = new Cache(new AgingMemory());
        $this->broker = new RecordingBroker();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv("{$name}={$value}");
        }
        MemoryDevice::reset();
    }

    public function testIdIsDeterministicLowercaseAnd45Characters(): void
    {
        $artifact = new Document(['$id' => 'artifact1']);
        $id = Builds::id($this->project, $artifact, Builds::STAGE_INSPECT);

        $this->assertSame($id, Builds::id($this->project, $artifact, Builds::STAGE_INSPECT, 1));
        $this->assertSame(45, \strlen($id));
        $this->assertStringStartsWith('dist-', $id);
        $this->assertSame(\strtolower($id), $id);
        $this->assertTrue(\ctype_xdigit(\substr($id, 5)));
    }

    public function testIdDiffersByProjectSubjectStageAndAttempt(): void
    {
        $artifact = new Document(['$id' => 'artifact1']);
        $ids = [
            Builds::id($this->project, $artifact, Builds::STAGE_INSPECT),
            Builds::id($this->project, $artifact, Builds::STAGE_SIGN),
            Builds::id($this->project, $artifact, Builds::STAGE_SIGN, 2),
            Builds::id($this->project, new Document(['$id' => 'artifact2']), Builds::STAGE_INSPECT),
            Builds::id(new Document(['$id' => 'project1', '$sequence' => '8']), $artifact, Builds::STAGE_INSPECT),
        ];

        $this->assertSame($ids, \array_values(\array_unique($ids)));
    }

    public function testLockIsScopedToTheProject(): void
    {
        $this->assertSame('distribution:build:7:artifact1', Builds::lock('7', 'artifact1'));
        $this->assertNotSame(Builds::lock('7', 'artifact1'), Builds::lock('8', 'artifact1'));
    }

    public function testPathNamesTheFileByArtifactAndType(): void
    {
        $this->assertSame('artifact1.aab', Builds::path('artifact1', 'aab'));
        $this->assertSame('artifact1.ipa', Builds::path('artifact1', 'ipa'));
    }

    /**
     * @return \Iterator<string, array{string, int, ?string}>
     */
    public static function exits(): \Iterator
    {
        yield 'inspect success' => [Builds::STAGE_INSPECT, 0, null];
        yield 'inspect malformed' => [Builds::STAGE_INSPECT, 2, Builds::ERROR_ARTIFACT_MALFORMED];
        yield 'inspect invalid' => [Builds::STAGE_INSPECT, 3, Builds::ERROR_ARTIFACT_INVALID];
        yield 'inspect usage' => [Builds::STAGE_INSPECT, 4, Builds::ERROR_JOB_FAILED];
        yield 'inspect java timeout' => [Builds::STAGE_INSPECT, 8, Builds::ERROR_JOB_TIMEOUT];
        yield 'inspect interrupted' => [Builds::STAGE_INSPECT, 130, Builds::ERROR_JOB_FAILED];
        yield 'inspect terminated' => [Builds::STAGE_INSPECT, 143, Builds::ERROR_JOB_FAILED];
        yield 'inspect unknown' => [Builds::STAGE_INSPECT, 1, Builds::ERROR_JOB_FAILED];
        yield 'sign success' => [Builds::STAGE_SIGN, 0, null];
        yield 'sign input invalid' => [Builds::STAGE_SIGN, 3, Builds::ERROR_SIGNING_INPUT_INVALID];
        yield 'sign already signed' => [Builds::STAGE_SIGN, 4, Builds::ERROR_ARTIFACT_ALREADY_SIGNED];
        yield 'sign failed' => [Builds::STAGE_SIGN, 5, Builds::ERROR_SIGNING_FAILED];
        yield 'sign verification failed' => [Builds::STAGE_SIGN, 6, Builds::ERROR_SIGNING_VERIFICATION_FAILED];
        yield 'sign bundle invalid' => [Builds::STAGE_SIGN, 7, Builds::ERROR_SIGNING_BUNDLE_INVALID];
        yield 'sign java timeout' => [Builds::STAGE_SIGN, 8, Builds::ERROR_JOB_TIMEOUT];
        yield 'sign interrupted' => [Builds::STAGE_SIGN, 130, Builds::ERROR_JOB_FAILED];
        yield 'sign terminated' => [Builds::STAGE_SIGN, 143, Builds::ERROR_JOB_FAILED];
        yield 'sign killed' => [Builds::STAGE_SIGN, 137, Builds::ERROR_JOB_FAILED];
        yield 'build success' => [Builds::STAGE_BUILD, 0, null];
        yield 'build failed' => [Builds::STAGE_BUILD, 1, Builds::ERROR_BUILD_FAILED];
        yield 'build invalid' => [Builds::STAGE_BUILD, 2, Builds::ERROR_BUILD_INVALID];
        yield 'build already signed' => [Builds::STAGE_BUILD, 3, Builds::ERROR_BUILD_ALREADY_SIGNED];
        yield 'build keystore required' => [Builds::STAGE_BUILD, 4, Builds::ERROR_BUILD_KEYSTORE_REQUIRED];
        yield 'build killed' => [Builds::STAGE_BUILD, 137, Builds::ERROR_BUILD_FAILED];
        yield 'build terminated' => [Builds::STAGE_BUILD, 143, Builds::ERROR_BUILD_FAILED];
    }

    #[DataProvider('exits')]
    public function testExitCodeMapsToError(string $stage, int $exitCode, ?string $error): void
    {
        $this->assertSame($error, Builds::error($stage, $exitCode));
    }

    /**
     * @return \Iterator<string, array{string, string, string, string}>
     */
    public static function secrets(): \Iterator
    {
        $build = \hash_hmac('sha256', 'distribution:build', 'jobs-api-secret');
        $signing = \hash_hmac('sha256', 'distribution:signing', 'jobs-api-secret');

        yield 'build uses the build key' => [Builds::STAGE_BUILD, 'build-callback-secret', 'signing-callback-secret', 'build-callback-secret'];
        yield 'build derives from the jobs secret' => [Builds::STAGE_BUILD, '', 'signing-callback-secret', $build];
        yield 'sign uses the signing key' => [Builds::STAGE_SIGN, 'build-callback-secret', 'signing-callback-secret', 'signing-callback-secret'];
        yield 'inspect uses the signing key' => [Builds::STAGE_INSPECT, 'build-callback-secret', 'signing-callback-secret', 'signing-callback-secret'];
        yield 'sign never falls back to the build key' => [Builds::STAGE_SIGN, 'build-callback-secret', '', $signing];
        yield 'inspect never falls back to the build key' => [Builds::STAGE_INSPECT, 'build-callback-secret', '', $signing];
        yield 'sign derives from the jobs secret' => [Builds::STAGE_SIGN, '', '', $signing];
        yield 'inspect derives from the jobs secret' => [Builds::STAGE_INSPECT, '', '', $signing];
    }

    #[DataProvider('secrets')]
    public function testSecretIsThePlaneCallbackKey(string $stage, string $build, string $signing, string $expected): void
    {
        \putenv("_APP_DISTRIBUTION_CALLBACK_SECRET={$build}");
        \putenv("_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET={$signing}");

        $this->assertSame($expected, Builds::secret($stage));
    }

    public function testFallbackKeysAreSeparatedByPlane(): void
    {
        \putenv('_APP_DISTRIBUTION_CALLBACK_SECRET=');
        \putenv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET=');

        $build = Builds::secret(Builds::STAGE_BUILD);
        $signing = Builds::secret(Builds::STAGE_SIGN);

        $this->assertNotSame($build, $signing);
        $this->assertNotSame('jobs-api-secret', $build);
        $this->assertNotSame('jobs-api-secret', $signing);
        $this->assertSame($signing, Builds::secret(Builds::STAGE_INSPECT));
    }

    public function testBuildKeyCannotForgeSigningEvent(): void
    {
        \putenv('_APP_DISTRIBUTION_CALLBACK_SECRET=');
        \putenv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET=');

        foreach ([Builds::STAGE_SIGN, Builds::STAGE_INSPECT] as $stage) {
            $body = (string) \json_encode([
                'type' => 'orchestrator.job.exit',
                'data' => ['exitCode' => 0, 'meta' => ['projectId' => 'project1', 'stage' => $stage]],
            ]);
            $forged = 'sha256=' . \hash_hmac('sha256', $body, Builds::secret(Builds::STAGE_BUILD));

            $this->assertTrue(JobsSecret::verify($body, $forged, Builds::secret(Builds::STAGE_BUILD)), $stage);
            $this->assertFalse(JobsSecret::verify($body, $forged, Builds::secret($stage)), $stage);
        }
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function insecureJobsSecrets(): \Iterator
    {
        yield 'empty' => [''];
        yield 'placeholder' => [JobsSecret::PLACEHOLDER];
    }

    #[DataProvider('insecureJobsSecrets')]
    public function testInsecureJobsSecretIsNeverDerived(string $jobsSecret): void
    {
        \putenv("_APP_JOBS_SECRET={$jobsSecret}");
        \putenv('_APP_DISTRIBUTION_CALLBACK_SECRET=');
        \putenv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET=');
        $body = '{"type":"orchestrator.job.exit"}';

        foreach ([Builds::STAGE_BUILD, Builds::STAGE_INSPECT, Builds::STAGE_SIGN] as $stage) {
            $secret = Builds::secret($stage);

            $this->assertSame($jobsSecret, $secret, $stage);
            $this->assertFalse(JobsSecret::verify($body, 'sha256=' . \hash_hmac('sha256', $body, $secret), $secret), $stage);
        }
    }

    public function testSigningJobNeverSignsWithTheBuildKey(): void
    {
        \putenv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET=');

        $payload = $this->builds(LocalBuilds::class)->inspectionPayload($this->project, $this->artifact('aab'));

        $callback = $payload['callback'];
        $this->assertInstanceOf(Callback::class, $callback);
        $this->assertSame(\hash_hmac('sha256', 'distribution:signing', 'jobs-api-secret'), $callback->key);
        $this->assertNotSame(Builds::secret(Builds::STAGE_BUILD), $callback->key);
    }

    /**
     * @return \Iterator<string, array{int, bool}>
     */
    public static function ages(): \Iterator
    {
        yield 'now' => [0, false];
        yield 'at the tolerance' => [900, false];
        yield 'past the tolerance' => [901, true];
        yield 'from the future at the tolerance' => [-900, false];
        yield 'from the future past the tolerance' => [-901, true];
    }

    #[DataProvider('ages')]
    public function testStaleRejectsEventsOutsideTheTolerance(int $age, bool $stale): void
    {
        $now = 1_800_000_000;

        $this->assertSame($stale, Builds::stale($now - $age, $now));
    }

    public function testLocalInspectionMountsTheProjectReadOnly(): void
    {
        $payload = $this->builds(LocalBuilds::class)->inspectionPayload($this->project, $this->artifact('ipa'));

        $this->assertSame(Builds::id($this->project, $this->artifact('ipa'), Builds::STAGE_INSPECT), $payload['id']);
        $this->assertSame(Config::getParam('distribution')['images']['tools'], $payload['image']);
        $this->assertSame('/usr/local/distribution/inspect.sh', $payload['command']);
        $this->assertSame('/mnt/code', $payload['workspace']);
        $this->assertSame(Builds::TIMEOUT, $payload['timeoutSeconds']);
        $this->assertSame([
            'DISTRIBUTION_ARTIFACT_TYPE' => 'ipa',
            'DISTRIBUTION_INPUT' => '/mnt/storage/artifact1.ipa',
        ], $payload['environment']);

        $this->assertCount(1, $payload['volumes']);
        $volume = $payload['volumes'][0];
        $this->assertInstanceOf(Volume::class, $volume);
        $this->assertSame('appwrite-distribution', $volume->source);
        $this->assertSame('/mnt/storage', $volume->path);
        $this->assertSame('app-project1', $volume->subPath);
        $this->assertTrue($volume->readonly);

        $this->assertCount(1, $payload['artifacts']);
        $this->assertResult($payload['artifacts'][0]);
        $this->assertMeta($payload, Builds::STAGE_INSPECT);
        $this->assertCallback($payload);
    }

    public function testLocalSigningWritesNextToTheInput(): void
    {
        $payload = $this->builds(LocalBuilds::class)->signingPayload($this->project, $this->artifact('aab'), $this->target(), 'token1');

        $this->assertSame('/usr/local/distribution/sign-android.sh', $payload['command']);
        $this->assertSame([
            'DISTRIBUTION_IDENTIFIER' => 'io.appwrite.example',
            'DISTRIBUTION_VERSION_CODE' => '57',
            'DISTRIBUTION_INPUT' => '/mnt/storage/artifact1.aab',
            'DISTRIBUTION_OUTPUT_DIR' => '/mnt/storage/signed/artifact1',
        ], $payload['environment']);
        $this->assertDirectoryExists(LocalBuilds::root('project1') . '/signed/artifact1');

        $this->assertCount(1, $payload['volumes']);
        $this->assertSame('app-project1', $payload['volumes'][0]->subPath);
        $this->assertFalse($payload['volumes'][0]->readonly);

        $this->assertCount(2, $payload['artifacts']);
        $this->assertSigningDownload($payload['artifacts'][0], 'token1');
        $this->assertResult($payload['artifacts'][1]);
        $this->assertMeta($payload, Builds::STAGE_SIGN);
        $this->assertCallback($payload);
    }

    public function testRemoteInspectionDownloadsTheArtifact(): void
    {
        $payload = $this->builds(RemoteBuilds::class)->inspectionPayload($this->project, $this->artifact('aab'));

        $this->assertSame([], $payload['volumes']);
        $this->assertSame(['DISTRIBUTION_ARTIFACT_TYPE' => 'aab'], $payload['environment']);

        [$input, $result] = $payload['artifacts'];
        $this->assertInstanceOf(DownloadArtifact::class, $input);
        $this->assertSame('s3://distribution/app-project1/artifact1.aab', $input->in);
        $this->assertSame('input/artifact', $input->out);
        $this->assertSame(Builds::TRANSFER_TIMEOUT, $input->timeoutSeconds);
        $this->assertResult($result);
    }

    public function testRemoteSigningUploadsTheSignedBundle(): void
    {
        $signed = $this->builds(MemoryBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), new Document(['$id' => 'identity1', 'secret' => self::IDENTITY]));

        $job = $this->signingPlane->job();
        $this->assertSame([], $job['volumes'] ?? []);
        $this->assertSame([
            'DISTRIBUTION_IDENTIFIER' => 'io.appwrite.example',
            'DISTRIBUTION_VERSION_CODE' => '57',
        ], $job['environment']);

        [$signing, $input, $output, $copy, $result] = $job['artifacts'];
        $this->assertSame('signing', $signing['id']);
        $this->assertSame('s3://distribution/app-project1/' . $signed->getAttribute('metadata')['job']['bundle'], $signing['in']);
        $this->assertSame('signing/bundle.json', $signing['out']);

        $this->assertSame('input', $input['id']);
        $this->assertSame('s3://distribution/app-project1/artifact1.aab', $input['in']);
        $this->assertSame('input/app-unsigned.aab', $input['out']);

        $this->assertSame('output', $output['id']);
        $this->assertSame('output/app.aab', $output['in']);
        $this->assertSame('s3://distribution/app-project1/signed/artifact1/app.aab', $output['out']);
        $this->assertSame('job', $output['depends']);

        $this->assertSame('resultCopy', $copy['id']);
        $this->assertSame('output/result.json', $copy['in']);
        $this->assertSame('s3://distribution/app-project1/signed/artifact1/result.json', $copy['out']);

        $this->assertSame('result', $result['id']);
        $this->assertSame('output/result.json', $result['in']);
    }

    public function testSignSubmitsNoSigningMaterial(): void
    {
        $artifact = $this->stored($this->artifact('aab'));
        $identity = new Document(['$id' => 'identity1', 'platform' => 'android', 'secret' => self::IDENTITY]);

        $signed = $this->builds(LocalBuilds::class)->sign($artifact, $this->target(), $identity);

        $job = $this->signingPlane->job();
        $this->assertSame([], $this->buildPlane->requests);
        $this->assertNotSame([], $job);

        $exposed = (string) \json_encode([
            $job['environment'],
            $job['meta'],
            $job['artifacts'],
            $job['volumes'] ?? [],
            $job['command'],
        ]);
        foreach ([...\array_values(self::IDENTITY), ...\array_values(self::SECRETS)] as $secret) {
            if (\str_starts_with($secret, 'http') || $secret === 'appwrite-distribution') {
                continue;
            }
            $this->assertStringNotContainsString($secret, $exposed);
        }
        $this->assertStringNotContainsString(\base64_decode(self::IDENTITY['keystore']), $exposed);
        $this->assertSame('signing-callback-secret', $job['callback']['key']);

        $headed = \array_values(\array_filter($job['artifacts'], fn (array $artifact): bool => isset($artifact['headers'])));
        $this->assertCount(1, $headed);
        $this->assertSame('signing', $headed[0]['id']);
        $this->assertSame('signing/bundle.json', $headed[0]['out']);

        $token = \substr($headed[0]['headers']['authorization'], \strlen('Bearer '));
        $this->assertSame(
            ['appId' => 'app1', 'subjectId' => 'artifact1', 'attempt' => 1],
            (new Signing($this->cache))->redeem($this->project, $token),
        );

        $this->assertSame(Builds::STAGE_SIGN, $signed->getAttribute('metadata')['job']['stage']);
        $this->assertSame($job['id'], $signed->getAttribute('metadata')['job']['id']);
        $this->assertSame('identity1', $signed->getAttribute('metadata')['job']['signingIdentityId']);
        $this->assertSame('upload1', $signed->getAttribute('metadata')['uploadId']);
    }

    public function testRemoteSignDeliversTheBundleAsAOneTimeObject(): void
    {
        $artifact = $this->stored($this->artifact('aab'));
        $identity = new Document(['$id' => 'identity1', 'platform' => 'android', 'secret' => self::IDENTITY]);

        $signed = $this->builds(MemoryBuilds::class)->sign($artifact, $this->target(), $identity);

        $job = $this->signingPlane->job();
        $this->assertNotSame([], $job);
        $download = $job['artifacts'][0];
        $this->assertSame('signing', $download['id']);
        $this->assertSame('signing/bundle.json', $download['out']);
        $this->assertSame(Builds::SIGNING_TIMEOUT, $download['timeoutSeconds']);
        $this->assertArrayNotHasKey('headers', $download);
        $this->assertSame([], \array_values(\array_filter($job['artifacts'], fn (array $artifact): bool => isset($artifact['headers']))));

        $bundle = $signed->getAttribute('metadata')['job']['bundle'];
        $prefix = 'signing/artifact1/1-';
        $this->assertStringStartsWith($prefix, $bundle);
        $this->assertStringEndsWith('.json', $bundle);
        $key = \substr($bundle, \strlen($prefix), -\strlen('.json'));
        $this->assertSame(64, \strlen($key));
        $this->assertTrue(\ctype_xdigit($key));
        $this->assertSame('s3://distribution/app-project1/' . $bundle, $download['in']);

        $this->assertSame(['distribution/app-project1/' . $bundle], \array_keys(MemoryDevice::$objects));
        $this->assertSame(self::IDENTITY, \json_decode(MemoryDevice::$objects['distribution/app-project1/' . $bundle], true));

        $exposed = (string) \json_encode([$job['environment'], $job['meta'], $job['artifacts'], $job['command']]);
        foreach (self::IDENTITY as $secret) {
            $this->assertStringNotContainsString($secret, $exposed);
        }
    }

    public function testRemoteSignNamesADifferentObjectEachTime(): void
    {
        $identity = new Document(['$id' => 'identity1', 'secret' => self::IDENTITY]);
        $first = $this->builds(MemoryBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), $identity);
        $this->dbForProject->documents['distributionArtifacts'] = [];
        $second = $this->builds(MemoryBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), $identity);

        $this->assertNotSame($first->getAttribute('metadata')['job']['bundle'], $second->getAttribute('metadata')['job']['bundle']);
    }

    public function testCancelDeletesTheRemoteBundle(): void
    {
        $signed = $this->builds(MemoryBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), new Document(['$id' => 'identity1', 'secret' => self::IDENTITY]));
        $this->assertCount(1, MemoryDevice::$objects);

        $this->builds(MemoryBuilds::class)->cancel($signed);

        $this->assertSame([], MemoryDevice::$objects);
    }

    public function testRefusedRemoteSignDeletesTheBundle(): void
    {
        $this->signingPlane = new Orchestrator(['POST' => 500]);

        try {
            $this->builds(MemoryBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), new Document(['$id' => 'identity1', 'secret' => self::IDENTITY]));
            $this->fail('A refused job must surface.');
        } catch (ApiException) {
        }

        $this->assertSame([], MemoryDevice::$objects);
        $this->assertSame('invalid', $this->dbForProject->getDocument('distributionArtifacts', 'artifact1')->getAttribute('status'));
    }

    public function testInspectRecordsTheJobAndSchedule(): void
    {
        $artifact = $this->stored($this->artifact('aab'));

        $inspected = $this->builds(LocalBuilds::class)->inspect($artifact);

        $job = $this->signingPlane->job();
        $this->assertSame(Builds::id($this->project, $artifact, Builds::STAGE_INSPECT), $job['id']);
        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame([
            'stage' => Builds::STAGE_INSPECT,
            'id' => $job['id'],
            'attempt' => 1,
        ], \array_intersect_key($inspected->getAttribute('metadata')['job'], ['stage' => 0, 'id' => 0, 'attempt' => 0]));
        $this->assertNotEmpty($inspected->getAttribute('metadata')['job']['dispatchedAt']);
        $this->assertSame('processing', $inspected->getAttribute('status'));

        $schedule = $this->schedule('artifact1');
        $this->assertTrue($schedule->getAttribute('active'));
        $this->assertSame(1, $schedule->getAttribute('interval'));
        $this->assertSame(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $schedule->getAttribute('resourceType'));
    }

    public function testInspectTreatsAnExistingJobAsSubmitted(): void
    {
        $this->signingPlane = new Orchestrator(['POST' => 409]);
        $artifact = $this->stored($this->artifact('aab'));

        $inspected = $this->builds(LocalBuilds::class)->inspect($artifact);

        $this->assertSame('processing', $inspected->getAttribute('status'));
        $this->assertTrue($this->schedule('artifact1')->getAttribute('active'));
    }

    public function testInspectSkipsAnArtifactThatIsNotProcessing(): void
    {
        $artifact = $this->stored(new Document([...$this->artifact('aab')->getArrayCopy(), 'status' => 'ready']));

        $this->builds(LocalBuilds::class)->inspect($artifact);

        $this->assertSame([], $this->signingPlane->requests);
        $this->assertTrue($this->schedule('artifact1')->isEmpty());
    }

    public function testRefusedInspectInvalidatesTheArtifactAndStopsItsSchedule(): void
    {
        $this->signingPlane = new Orchestrator(['POST' => 500]);
        $artifact = $this->stored($this->artifact('aab'));

        try {
            $this->builds(LocalBuilds::class)->inspect($artifact);
            $this->fail('A refused job must surface.');
        } catch (ApiException $error) {
            $this->assertSame(500, $error->statusCode);
        }

        $stored = $this->dbForProject->getDocument('distributionArtifacts', 'artifact1');
        $this->assertSame('invalid', $stored->getAttribute('status'));
        $this->assertSame(Builds::ERROR_JOB_FAILED, $stored->getAttribute('errorCode'));
        $this->assertFalse($this->schedule('artifact1')->getAttribute('active'));
    }

    public function testRefusedSignRevokesItsToken(): void
    {
        $this->signingPlane = new Orchestrator(['POST' => 500]);
        $artifact = $this->stored($this->artifact('aab'));

        try {
            $this->builds(LocalBuilds::class)->sign($artifact, $this->target(), new Document(['$id' => 'identity1']));
            $this->fail('A refused job must surface.');
        } catch (ApiException) {
        }

        $this->assertNull((new Signing($this->cache))->redeem($this->project, $this->token()));
    }

    public function testCancelToleratesAJobThatIsGone(): void
    {
        $this->signingPlane = new Orchestrator(['DELETE' => 404]);
        $artifact = $this->inspecting();

        $this->builds(LocalBuilds::class)->cancel($artifact);

        [$submit, $delete] = $this->signingPlane->requests;
        $this->assertSame('POST', $submit->getMethod());
        $this->assertSame('DELETE', $delete->getMethod());
        $this->assertSame('/v1/jobs/' . Builds::id($this->project, $artifact, Builds::STAGE_INSPECT), $delete->getUri()->getPath());
        $this->assertSame([], $this->buildPlane->requests);
    }

    public function testCancelSurfacesOtherFailures(): void
    {
        $this->signingPlane = new Orchestrator(['DELETE' => 500]);

        $this->expectException(ApiException::class);

        $this->builds(LocalBuilds::class)->cancel($this->inspecting());
    }

    public function testCancelDeletesTheRemoteBundleWhenTheJobCannotBeDeleted(): void
    {
        $signed = $this->builds(MemoryBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), new Document(['$id' => 'identity1', 'secret' => self::IDENTITY]));
        $this->signingPlane = new Orchestrator(['DELETE' => 500]);

        try {
            $this->builds(MemoryBuilds::class)->cancel($signed);
            $this->fail('A refused delete must surface.');
        } catch (ApiException $error) {
            $this->assertSame(500, $error->statusCode);
        }

        $this->assertSame([], MemoryDevice::$objects);
    }

    public function testCancelRevokesTheSigningTokenWhenTheJobCannotBeDeleted(): void
    {
        $signed = $this->builds(LocalBuilds::class)->sign($this->stored($this->artifact('aab')), $this->target(), new Document(['$id' => 'identity1']));
        $token = $this->token();
        $this->signingPlane = new Orchestrator(['DELETE' => 500]);

        try {
            $this->builds(LocalBuilds::class)->cancel($signed);
            $this->fail('A refused delete must surface.');
        } catch (ApiException) {
        }

        $this->assertNull((new Signing($this->cache))->redeem($this->project, $token));
    }

    public function testCancelRevokesTheSigningToken(): void
    {
        $artifact = $this->stored($this->artifact('aab'));
        $signed = $this->builds(LocalBuilds::class)->sign($artifact, $this->target(), new Document(['$id' => 'identity1']));
        $token = $this->token();

        $this->builds(LocalBuilds::class)->cancel($signed);

        $this->assertSame('DELETE', $this->signingPlane->requests[1]->getMethod());
        $this->assertSame('/v1/jobs/' . Builds::id($this->project, $artifact, Builds::STAGE_SIGN), $this->signingPlane->requests[1]->getUri()->getPath());
        $this->assertNull((new Signing($this->cache))->redeem($this->project, $token));
    }

    public function testCancelDeletesABuildJobOnTheBuildPlane(): void
    {
        $build = new Document([
            '$id' => 'build1',
            '$collection' => 'distributionBuilds',
            'runner' => 'orchestrator',
            'stage' => 'build',
            'attempt' => 2,
            'jobId' => 'dist-build-job',
        ]);

        $this->builds(LocalBuilds::class)->cancel($build);

        $this->assertCount(1, $this->buildPlane->requests);
        $this->assertSame('/v1/jobs/dist-build-job', $this->buildPlane->requests[0]->getUri()->getPath());
        $this->assertSame([], $this->signingPlane->requests);
    }

    public function testCancelReleasesThePoolLease(): void
    {
        foreach (['build1' => 'leased', 'build2' => 'leased'] as $buildId => $status) {
            $this->dbForPlatform->createDocument('distributionLeases', new Document([
                'projectInternalId' => '7',
                'buildId' => $buildId,
                'status' => $status,
            ]));
        }
        $build = new Document(['$id' => 'build1', '$collection' => 'distributionBuilds', 'runner' => 'pool', 'stage' => 'build']);

        $this->builds(LocalBuilds::class)->cancel($build);

        $statuses = \array_map(fn (Document $lease): string => $lease->getAttribute('status'), $this->dbForPlatform->documents['distributionLeases']);
        $this->assertSame(['released', 'leased'], \array_values($statuses));
        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame([], $this->signingPlane->requests);
    }

    public function testActiveAndTerminalPartitionTheBuildStatuses(): void
    {
        $statuses = [...Builds::ACTIVE, ...Builds::TERMINAL];
        \sort($statuses);
        $values = BuildStatus::values();
        \sort($values);

        $this->assertSame($values, $statuses);
        $this->assertSame([], \array_intersect(Builds::ACTIVE, Builds::TERMINAL));

        foreach (Builds::ACTIVE as $status) {
            $this->assertTrue(BuildStatus::from($status)->canTransition(BuildStatus::Canceled), "{$status} can be canceled");
        }

        foreach (Builds::TERMINAL as $status) {
            $this->assertFalse(BuildStatus::from($status)->canTransition(BuildStatus::Canceled), "{$status} cannot be canceled");
        }
    }

    public function testOutputAndCacheNameTheBuildFiles(): void
    {
        $cache = Builds::cache($this->project, 'target1', 'appwrite/distribution-android:1.0.0');

        $this->assertSame('builds/build1', Builds::output('build1'));
        $this->assertSame($cache, Builds::cache($this->project, 'target1', 'appwrite/distribution-android:1.0.0'));
        $this->assertStringStartsWith('cache/', $cache);
        $this->assertStringEndsWith('/cache.sqfs', $cache);
        $this->assertSame(48, \strlen(\substr($cache, \strlen('cache/'), -\strlen('/cache.sqfs'))));
        $this->assertNotSame($cache, Builds::cache($this->project, 'target2', 'appwrite/distribution-android:1.0.0'));
        $this->assertNotSame($cache, Builds::cache($this->project, 'target1', 'appwrite/distribution-android:1.0.1'));
        $this->assertNotSame($cache, Builds::cache(new Document(['$id' => 'project2']), 'target1', 'appwrite/distribution-android:1.0.0'));
    }

    public function testEveryBuildErrorHasAMessage(): void
    {
        foreach ([
            Builds::ERROR_BUILD_FAILED,
            Builds::ERROR_BUILD_TIMEOUT,
            Builds::ERROR_BUILD_OUT_OF_MEMORY,
            Builds::ERROR_BUILD_LOST,
            Builds::ERROR_BUILD_INVALID,
            Builds::ERROR_BUILD_ALREADY_SIGNED,
            Builds::ERROR_BUILD_KEYSTORE_REQUIRED,
            Builds::ERROR_BUILD_CANCELED,
            Builds::ERROR_BUILD_OUTPUT_INVALID,
            Builds::ERROR_BUILD_SOURCE_FAILED,
            Builds::ERROR_RUNNER_UNAVAILABLE,
            Builds::ERROR_VARIABLE_INVALID,
            Builds::ERROR_RUNNER_LOST,
        ] as $code) {
            $this->assertNotSame('', Builds::message($code), "{$code} needs a message.");
        }

        $this->assertSame('', Builds::message('unknown'));
        $this->assertSame('Your build script requires a keystore file; guard it or remove the signing config.', Builds::message(Builds::ERROR_BUILD_KEYSTORE_REQUIRED));
    }

    /**
     * @return \Iterator<string, array{?int, int, int}>
     */
    public static function runtimes(): \Iterator
    {
        yield 'never started' => [null, 3600, 0];
        yield 'running' => [100, 3600, 100];
        yield 'past the timeout' => [5000, 3600, 3600];
    }

    #[DataProvider('runtimes')]
    public function testRuntimeIsCappedByTheTimeout(?int $age, int $timeout, int $expected): void
    {
        $build = new Document([
            'startedAt' => $age === null ? null : DateTime::addSeconds(new \DateTime(), -$age),
            'timeout' => $timeout,
        ]);

        $this->assertEqualsWithDelta($expected, Builds::runtime($build), 1);
    }

    public function testBuildPayloadCarriesNoKeystoreStoreCredentialOrApiKey(): void
    {
        $this->dbForProject->createDocument('distributionSigningIdentities', new Document(['$id' => 'identity1', 'platform' => 'android', 'secret' => self::IDENTITY]));
        $this->dbForProject->createDocument('distributionConnections', new Document(['$id' => 'connection1', 'provider' => 'google', 'credentials' => ['serviceAccount' => self::CREDENTIAL]]));
        $app = $this->storedApp();
        $this->storedTarget(['signingIdentityId' => 'identity1', 'connectionId' => 'connection1']);
        $this->variable('project', '', 'PROJECT_TOKEN', 'project-value');
        $this->variable('distributionApps', $app->getSequence(), 'APP_TOKEN', 'app-value');

        foreach ([LocalBuilds::class, MemoryBuilds::class] as $class) {
            $this->buildPlane = new Orchestrator();
            $this->dbForProject->documents['distributionBuilds'] = [];
            $this->builds($class)->dispatch($this->storedBuild(), new RecordingGit());

            $job = $this->buildPlane->job();
            $this->assertNotSame([], $job);
            $this->assertSame([], $this->signingPlane->requests);

            $exposed = (string) \json_encode([$job['environment'], $job['meta'], $job['artifacts'], $job['volumes'] ?? [], $job['command']]);
            foreach ([...\array_values(self::IDENTITY), self::CREDENTIAL, ...\array_values(self::SECRETS)] as $secret) {
                if (\str_starts_with($secret, 'http') || $secret === 'appwrite-distribution') {
                    continue;
                }
                $this->assertStringNotContainsString($secret, $exposed, "{$class} leaks {$secret}.");
            }
            $this->assertStringNotContainsString(\base64_decode(self::IDENTITY['keystore']), $exposed);
            $this->assertStringNotContainsString('API_KEY', $exposed);
            $this->assertSame([], \array_filter(\array_keys($job['environment']), fn (string $key): bool => \str_starts_with($key, 'APPWRITE_') && \str_ends_with($key, '_API_KEY')));
            $this->assertSame('project-value', $job['environment']['PROJECT_TOKEN']);
            $this->assertSame('app-value', $job['environment']['APP_TOKEN']);
        }
    }

    public function testBuildPayloadUsesTheBuildPlaneKeyAndLogs(): void
    {
        $payload = $this->builds(LocalBuilds::class)->buildPayload($this->app(), $this->buildTarget(), $this->build());

        $callback = $payload['callback'];
        $this->assertInstanceOf(Callback::class, $callback);
        $this->assertSame('build-callback-secret', $callback->key);
        $this->assertSame('http://appwrite/v1/distribution/events?project=project1', $callback->url);
        $this->assertSame([
            'orchestrator.job.start',
            'orchestrator.job.log',
            'orchestrator.job.exit',
            'orchestrator.job.complete',
            'orchestrator.job.artifact',
        ], $callback->toArray()['events']);

        $this->assertSame(Config::getParam('distribution')['images']['android'], $payload['image']);
        $this->assertSame('/usr/local/distribution/build.sh', $payload['command']);
        $this->assertSame('/mnt/code', $payload['workspace']);
        $this->assertSame(3600 + Builds::SETUP, $payload['timeoutSeconds']);
        $this->assertEqualsWithDelta(4.0, $payload['cpu'], PHP_FLOAT_EPSILON);
        $this->assertSame(8192, $payload['memory']);
        $this->assertSame([
            'projectId' => 'project1',
            'appId' => 'app1',
            'buildId' => 'build1',
            'stage' => 'build',
            'attempt' => '1',
        ], $payload['meta']);
    }

    public function testBuildPayloadExtractsTheWholeArchive(): void
    {
        $payload = $this->builds(LocalBuilds::class)->buildPayload($this->app(), $this->buildTarget(), $this->build());

        [$source, $extract] = $payload['artifacts'];
        $this->assertInstanceOf(DownloadArtifact::class, $source);
        $this->assertSame('source', $source->id);
        $this->assertSame(RecordingGit::ARCHIVE, $source->in);
        $this->assertSame('source.tar.gz', $source->out);
        $this->assertSame(RecordingGit::HEADERS, $source->headers);
        $this->assertSame(Builds::TRANSFER_TIMEOUT, $source->timeoutSeconds);

        $this->assertInstanceOf(UnarchiveArtifact::class, $extract);
        $this->assertSame('extract', $extract->id);
        $this->assertSame('source.tar.gz', $extract->in);
        $this->assertSame('source', $extract->out);
        $this->assertTrue($extract->strip);
        $this->assertNull($extract->subdir);
        $this->assertSame('source', $extract->depends);

        $this->assertResult($payload['artifacts'][\array_key_last($payload['artifacts'])]);
    }

    public function testSystemVariablesOverrideUserVariables(): void
    {
        $payload = $this->builds(LocalBuilds::class)->buildPayload($this->app(), $this->buildTarget(), $this->build(), [
            'MY_VARIABLE' => 'mine',
            'DISTRIBUTION_IDENTIFIER' => 'io.attacker.app',
            'DISTRIBUTION_VERSION_CODE' => '1',
            'APPWRITE_VCS_COMMIT_HASH' => 'forged',
            'CI' => 'false',
        ]);

        $this->assertSame([
            'MY_VARIABLE' => 'mine',
            'DISTRIBUTION_IDENTIFIER' => 'io.appwrite.example',
            'DISTRIBUTION_VERSION_CODE' => '7',
            'APPWRITE_VCS_COMMIT_HASH' => 'abc1234def5678',
            'CI' => 'true',
            'DISTRIBUTION_PLATFORM' => 'android',
            'DISTRIBUTION_FRAMEWORK' => 'flutter',
            'DISTRIBUTION_ROOT_DIRECTORY' => 'mobile',
            'DISTRIBUTION_INSTALL_COMMAND' => 'flutter pub get',
            'DISTRIBUTION_BUILD_COMMAND' => 'flutter build appbundle --release --flavor production',
            'DISTRIBUTION_OUTPUT_PATH' => 'build/app/outputs/bundle/release/*.aab',
            'DISTRIBUTION_CONFIGURATION' => '{"flavor":"production"}',
            'DISTRIBUTION_VERSION_NAME' => '1.2.3',
            'DISTRIBUTION_BUILD_ID' => 'build1',
            'DISTRIBUTION_OUTPUT_DIR' => '/mnt/output',
            'DISTRIBUTION_CACHE_ARTIFACT' => '/mnt/cache/cache.sqfs',
            'APPWRITE_VCS_REPOSITORY_ID' => '42',
            'APPWRITE_VCS_REPOSITORY_NAME' => 'mobile-app',
            'APPWRITE_VCS_REPOSITORY_OWNER' => 'appwrite',
            'APPWRITE_VCS_REPOSITORY_URL' => 'http://gitea:3000/appwrite/mobile-app',
            'APPWRITE_VCS_REPOSITORY_BRANCH' => 'main',
            'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => 'http://gitea:3000/appwrite/mobile-app/src/branch/main',
            'APPWRITE_VCS_COMMIT_MESSAGE' => "Ship it\n\nWith a body",
            'APPWRITE_VCS_COMMIT_URL' => 'http://gitea:3000/appwrite/mobile-app/commit/abc1234def5678',
            'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => 'Ada',
            'APPWRITE_VCS_COMMIT_AUTHOR_URL' => 'http://gitea:3000/ada',
            'APPWRITE_VCS_ROOT_DIRECTORY' => 'mobile',
        ], $payload['environment']);
    }

    public function testBuildPayloadDefaultsAnEmptyRootDirectoryAndConfiguration(): void
    {
        $payload = $this->builds(LocalBuilds::class)->buildPayload(
            $this->app(['framework' => 'android']),
            new Document([...$this->buildTarget()->getArrayCopy(), 'buildCommand' => '', 'configuration' => null]),
            new Document([...$this->build()->getArrayCopy(), 'providerRootDirectory' => '']),
        );

        $this->assertSame('.', $payload['environment']['DISTRIBUTION_ROOT_DIRECTORY']);
        $this->assertSame('{}', $payload['environment']['DISTRIBUTION_CONFIGURATION']);
        $this->assertSame('', $payload['environment']['DISTRIBUTION_INSTALL_COMMAND']);
        $this->assertSame('./gradlew --no-daemon bundleRelease', $payload['environment']['DISTRIBUTION_BUILD_COMMAND']);
        $this->assertSame('app/build/outputs/bundle/release/*.aab', $payload['environment']['DISTRIBUTION_OUTPUT_PATH']);
    }

    public function testLocalBuildMountsOnlyItsOutputAndTheCache(): void
    {
        $image = Config::getParam('distribution')['images']['android'];
        $key = \dirname(Builds::cache($this->project, 'target1', $image));
        $payload = $this->builds(LocalBuilds::class)->buildPayload($this->app(), $this->buildTarget(), $this->build());

        [$output, $cache] = $payload['volumes'];
        $this->assertCount(2, $payload['volumes']);
        $this->assertInstanceOf(Volume::class, $output);
        $this->assertSame('appwrite-distribution', $output->source);
        $this->assertSame('/mnt/output', $output->path);
        $this->assertSame('app-project1/builds/build1', $output->subPath);
        $this->assertFalse($output->readonly);
        $this->assertInstanceOf(Volume::class, $cache);
        $this->assertSame('/mnt/cache', $cache->path);
        $this->assertSame("app-project1/{$key}", $cache->subPath, 'only this target\'s cache key is mounted');
        $this->assertNotSame("app-project1/{$key}", 'app-project1/' . \dirname(Builds::cache($this->project, 'target2', $image)), 'another target of the project mounts another directory');
        $this->assertFalse($cache->readonly);
        $this->assertSame('/mnt/cache/cache.sqfs', $payload['environment']['DISTRIBUTION_CACHE_ARTIFACT']);

        foreach ($payload['volumes'] as $volume) {
            $this->assertNotSame('app-project1', $volume->subPath);
            $this->assertNotSame('app-project1/cache', $volume->subPath);
            $this->assertStringStartsNotWith('/mnt/code', $volume->path);
        }

        $this->assertDirectoryExists(LocalBuilds::root('project1') . '/builds/build1');
        $this->assertDirectoryExists(LocalBuilds::root('project1') . "/{$key}");
        $this->assertSame(['source', 'extract', 'result'], $this->ids($payload));
    }

    public function testRemoteBuildPullsTheCacheOnlyWhenItExists(): void
    {
        $cache = Builds::cache($this->project, 'target1', Config::getParam('distribution')['images']['android']);
        $builds = $this->builds(MemoryBuilds::class);

        $cold = $builds->buildPayload($this->app(), $this->buildTarget(), $this->build());
        $this->assertSame([], $cold['volumes']);
        $this->assertSame(['source', 'extract', 'output', 'resultCopy', 'mapping', 'cache', 'result'], $this->ids($cold));
        $this->assertSame($cache, $cold['environment']['DISTRIBUTION_CACHE_ARTIFACT']);
        $this->assertArrayNotHasKey('DISTRIBUTION_OUTPUT_DIR', $cold['environment']);

        $uploads = $this->artifacts($cold, UploadArtifact::class);
        $this->assertSame([
            'output' => ['output/app-unsigned.aab', 's3://distribution/app-project1/builds/build1/app-unsigned.aab', Builds::TRANSFER_TIMEOUT],
            'resultCopy' => ['output/result.json', 's3://distribution/app-project1/builds/build1/result.json', Builds::SIGNING_TIMEOUT],
            'mapping' => ['output/mapping.txt', 's3://distribution/app-project1/builds/build1/mapping.txt', Builds::SIGNING_TIMEOUT],
            'cache' => [$cache, "s3://distribution/app-project1/{$cache}", Builds::TRANSFER_TIMEOUT],
        ], \array_map(fn (UploadArtifact $upload): array => [$upload->in, $upload->out, $upload->timeoutSeconds], $uploads));
        foreach ($uploads as $upload) {
            $this->assertSame('job', $upload->depends);
        }

        MemoryDevice::$objects["distribution/app-project1/{$cache}"] = 'squashfs';
        $warm = $builds->buildPayload($this->app(), $this->buildTarget(), $this->build());

        $this->assertSame(['source', 'extract', 'cachePull', 'output', 'resultCopy', 'mapping', 'cache', 'result'], $this->ids($warm));
        $pull = $warm['artifacts'][2];
        $this->assertInstanceOf(DownloadArtifact::class, $pull);
        $this->assertSame("s3://distribution/app-project1/{$cache}", $pull->in);
        $this->assertSame($cache, $pull->out);
        $this->assertSame(Builds::TRANSFER_TIMEOUT, $pull->timeoutSeconds);
        $this->assertNull($pull->depends);
    }

    public function testBuildPostBudgetCoversEveryPostJobUpload(): void
    {
        $payload = $this->builds(MemoryBuilds::class)->buildPayload($this->app(), $this->buildTarget(), $this->build());
        $uploads = \array_filter($this->artifacts($payload, UploadArtifact::class), fn (UploadArtifact $upload): bool => $upload->depends === 'job');

        $this->assertCount(4, $uploads);
        $this->assertSame(Builds::BUILD_POST_BUDGET, \array_sum(\array_map(fn (UploadArtifact $upload): int => (int) $upload->timeoutSeconds * 4, $uploads)));
    }

    public function testBuildJobIdChangesWithTheAttempt(): void
    {
        $builds = $this->builds(LocalBuilds::class);
        $first = $builds->buildPayload($this->app(), $this->buildTarget(), $this->build());
        $second = $builds->buildPayload($this->app(), $this->buildTarget(), new Document([...$this->build()->getArrayCopy(), 'attempt' => 2]));

        $this->assertSame(Builds::id($this->project, $this->build(), Builds::STAGE_BUILD, 1), $first['id']);
        $this->assertSame(Builds::id($this->project, $this->build(), Builds::STAGE_BUILD, 2), $second['id']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame('2', $second['meta']['attempt']);
    }

    public function testDispatchRecordsJobScheduleAndProcessing(): void
    {
        $this->storedApp();
        $this->storedTarget();
        $git = new RecordingGit();

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(), $git);

        $job = $this->buildPlane->job();
        $this->assertSame(Builds::id($this->project, $dispatched, Builds::STAGE_BUILD, 1), $job['id']);
        $this->assertSame([], $this->signingPlane->requests);
        $this->assertSame([['appwrite', 'mobile-app', 'abc1234def5678']], $git->archives);
        $this->assertSame('processing', $dispatched->getAttribute('status'));
        $this->assertSame('build', $dispatched->getAttribute('stage'));
        $this->assertSame($job['id'], $dispatched->getAttribute('jobId'));
        $this->assertSame('orchestrator', $dispatched->getAttribute('runner'));
        $this->assertNotEmpty($dispatched->getAttribute('dispatchedAt'));
        $this->assertSame(['image' => Config::getParam('distribution')['images']['android'], 'cache' => false], $dispatched->getAttribute('toolchain'));

        $schedule = $this->schedule('build1');
        $this->assertTrue($schedule->getAttribute('active'));
        $this->assertSame(1, $schedule->getAttribute('interval'));
        $this->assertSame(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $schedule->getAttribute('resourceType'));
    }

    public function testDispatchRecordsALocalCacheHit(): void
    {
        $this->storedApp();
        $this->storedTarget();
        $cache = LocalBuilds::root('project1') . '/' . Builds::cache($this->project, 'target1', Config::getParam('distribution')['images']['android']);
        @\mkdir(\dirname($cache), 0o777, true);
        \file_put_contents($cache, 'squashfs');

        try {
            $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(), new RecordingGit());
        } finally {
            \unlink($cache);
        }

        $this->assertTrue($dispatched->getAttribute('toolchain')['cache']);
    }

    public function testDispatchPassesProjectThenAppVariables(): void
    {
        $app = $this->storedApp();
        $this->storedTarget();
        $this->variable('project', '', 'SHARED', 'project');
        $this->variable('project', '', 'PROJECT_ONLY', 'project');
        $this->variable('distributionApps', $app->getSequence(), 'SHARED', 'app');
        $this->variable('distributionApps', 'another', 'OTHER_APP', 'other');

        $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(), new RecordingGit());

        $environment = $this->buildPlane->job()['environment'];
        $this->assertSame('app', $environment['SHARED']);
        $this->assertSame('project', $environment['PROJECT_ONLY']);
        $this->assertArrayNotHasKey('OTHER_APP', $environment);
    }

    public function testDispatchTreatsAConflictAsSubmitted(): void
    {
        $this->buildPlane = new Orchestrator(['POST' => 409]);
        $this->storedApp();
        $this->storedTarget();

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(), new RecordingGit());

        $this->assertSame('processing', $dispatched->getAttribute('status'));
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
    }

    public function testDispatchLeavesATimedOutBuildWaiting(): void
    {
        $this->buildPlane = new Orchestrator(['POST' => Orchestrator::TIMEOUT]);
        $this->storedApp();
        $this->storedTarget();

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(), new RecordingGit());

        $this->assertSame('waiting', $dispatched->getAttribute('status'));
        $this->assertSame(Builds::id($this->project, $dispatched, Builds::STAGE_BUILD, 1), $dispatched->getAttribute('jobId'));
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function undispatchable(): \Iterator
    {
        yield 'started' => ['processing', true];
        yield 'building' => ['building', true];
        yield 'signing' => ['signing', true];
        yield 'ready' => ['ready', false];
        yield 'failed' => ['failed', false];
        yield 'canceled' => ['canceled', false];
    }

    #[DataProvider('undispatchable')]
    public function testDispatchSkipsAStartedOrTerminalBuild(string $status, bool $started): void
    {
        $this->storedApp();
        $this->storedTarget();
        $build = $this->storedBuild(['status' => $status, 'startedAt' => $started ? DateTime::now() : null]);

        $skipped = $this->builds(LocalBuilds::class)->dispatch($build, new RecordingGit());

        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame($status, $skipped->getAttribute('status'));
        $this->assertTrue($this->schedule('build1')->isEmpty());
    }

    public function testDispatchSubmitsAProcessingBuildThatNeverStartedAgain(): void
    {
        $this->storedApp();
        $this->storedTarget();

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(['status' => 'processing']), new RecordingGit());

        $this->assertSame(Builds::id($this->project, $dispatched, Builds::STAGE_BUILD, 1), $this->buildPlane->job()['id']);
        $this->assertSame('processing', $dispatched->getAttribute('status'));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function resubmittable(): \Iterator
    {
        yield 'timed out while waiting' => ['waiting'];
        yield 'processing but never started' => ['processing'];
    }

    #[DataProvider('resubmittable')]
    public function testDispatchKeepsTheDispatchTimeOfTheSameAttempt(string $status): void
    {
        $this->storedApp();
        $this->storedTarget();
        $dispatchedAt = DateTime::addSeconds(new \DateTime(), -7200);
        $jobId = Builds::id($this->project, $this->build(), Builds::STAGE_BUILD, 1);

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(['status' => $status, 'jobId' => $jobId, 'dispatchedAt' => $dispatchedAt]), new RecordingGit());

        $this->assertSame($jobId, $this->buildPlane->job()['id']);
        $this->assertSame($jobId, $dispatched->getAttribute('jobId'));
        $this->assertSame($dispatchedAt, $dispatched->getAttribute('dispatchedAt'), 'a re-submit of the same job keeps counting toward the deadline');
    }

    public function testRedispatchResetsTheDispatchTime(): void
    {
        $this->storedApp();
        $this->storedTarget();
        $dispatchedAt = DateTime::addSeconds(new \DateTime(), -7200);
        $builds = $this->builds(LocalBuilds::class);
        $builds->dispatch($this->storedBuild(['jobId' => Builds::id($this->project, $this->build(), Builds::STAGE_BUILD, 1), 'dispatchedAt' => $dispatchedAt]), new RecordingGit());

        $redispatched = $builds->redispatch($this->started(), new RecordingGit());

        $this->assertSame(2, $redispatched->getAttribute('attempt'));
        $this->assertNotSame($dispatchedAt, $redispatched->getAttribute('dispatchedAt'), 'a new attempt starts its own deadline');
        $this->assertGreaterThan(\strtotime($dispatchedAt), \strtotime((string) $redispatched->getAttribute('dispatchedAt')));
    }

    public function testDispatchFailsAnInvalidVariableKey(): void
    {
        $app = $this->storedApp();
        $this->storedTarget();
        $this->variable('distributionApps', $app->getSequence(), 'BAD KEY', 'value');

        $failed = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(), new RecordingGit());

        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(Builds::ERROR_VARIABLE_INVALID, $failed->getAttribute('errorCode'));
        $this->assertStringContainsString('"BAD KEY"', (string) $failed->getAttribute('errorMessage'));
    }

    public function testDispatchFailsAnIosBuildWithoutRunners(): void
    {
        $this->storedApp();
        $this->storedTarget(['platform' => 'ios']);

        $failed = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(['platform' => 'ios', 'runner' => 'pool']), new RecordingGit());

        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame('distribution_runner_unavailable', $failed->getAttribute('errorCode'));
        $this->assertSame(0, $failed->getAttribute('duration'));
        $this->assertNotEmpty($failed->getAttribute('endedAt'));
    }

    public function testDispatchAfterACacheFailurePullsNoCache(): void
    {
        $this->storedApp();
        $this->storedTarget();
        $cache = Builds::cache($this->project, 'target1', Config::getParam('distribution')['images']['android']);
        MemoryDevice::$objects["distribution/app-project1/{$cache}"] = 'squashfs';

        $dispatched = $this->builds(MemoryBuilds::class)->dispatch($this->storedBuild(['toolchain' => ['cacheFailed' => true]]), new RecordingGit());

        $job = $this->buildPlane->job();
        $this->assertSame(['source', 'extract', 'output', 'resultCopy', 'mapping', 'result'], \array_column($job['artifacts'], 'id'));
        $this->assertArrayNotHasKey('DISTRIBUTION_CACHE_ARTIFACT', $job['environment']);
        $this->assertFalse($dispatched->getAttribute('toolchain')['cache']);
        $this->assertTrue($dispatched->getAttribute('toolchain')['cacheFailed']);
    }

    public function testLocalDispatchAfterACacheFailureMountsNoCache(): void
    {
        $this->storedApp();
        $this->storedTarget();

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->storedBuild(['toolchain' => ['cacheFailed' => true]]), new RecordingGit());

        $job = $this->buildPlane->job();
        $this->assertSame(['/mnt/output'], \array_column($job['volumes'], 'path'));
        $this->assertArrayNotHasKey('DISTRIBUTION_CACHE_ARTIFACT', $job['environment']);
        $this->assertSame('/mnt/output', $job['environment']['DISTRIBUTION_OUTPUT_DIR']);
        $this->assertTrue($dispatched->getAttribute('toolchain')['cacheFailed']);
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function failures(): \Iterator
    {
        yield 'missing app' => ['app', Builds::ERROR_BUILD_FAILED];
        yield 'missing target' => ['target', Builds::ERROR_BUILD_FAILED];
        yield 'invalid variable key' => ['variable', Builds::ERROR_VARIABLE_INVALID];
        yield 'unavailable source' => ['source', Builds::ERROR_BUILD_SOURCE_FAILED];
        yield 'refused job' => ['job', Builds::ERROR_BUILD_FAILED];
        yield 'ios' => ['ios', Builds::ERROR_RUNNER_UNAVAILABLE];
        yield 'failed enqueue' => ['enqueue', Builds::ERROR_BUILD_FAILED];
    }

    #[DataProvider('failures')]
    public function testEveryFailurePathLeavesAScheduleRow(string $failure, string $code): void
    {
        $app = $this->storedApp();
        $target = $this->storedTarget(['platform' => $failure === 'ios' ? 'ios' : 'android']);
        if ($failure === 'variable') {
            $this->variable('distributionApps', $app->getSequence(), '1INVALID', 'value');
        }
        if ($failure === 'job') {
            $this->buildPlane = new Orchestrator(['POST' => 500]);
        }
        if ($failure === 'enqueue') {
            $this->broker = new RecordingBroker(accepts: false);
        }

        try {
            if ($failure === 'enqueue') {
                $this->builds(LocalBuilds::class)->create($app, $target, $this->request(), new RecordingGit(), 'appwrite', 'mobile-app');
                $this->fail('A build that cannot be queued must surface.');
            }

            $this->builds(LocalBuilds::class)->dispatch($this->storedBuild([
                'appId' => $failure === 'app' ? 'missing' : 'app1',
                'targetId' => $failure === 'target' ? 'missing' : 'target1',
                'platform' => $failure === 'ios' ? 'ios' : 'android',
            ]), new RecordingGit(failure: $failure === 'source' ? new \RuntimeException('Archive refused') : null));

            if ($failure === 'job') {
                $this->fail('A refused job must surface.');
            }
        } catch (ApiException $error) {
            $this->assertSame('job', $failure);
            $this->assertSame(500, $error->statusCode);
        } catch (Exception $error) {
            $this->assertSame('enqueue', $failure);
            $this->assertSame(Exception::GENERAL_SERVER_ERROR, $error->getType());
        }

        $build = $this->dbForProject->getDocument('distributionBuilds', 'build1');
        $this->assertSame('failed', $build->getAttribute('status'));
        $this->assertSame($code, $build->getAttribute('errorCode'));
        $this->assertNotSame('', (string) $build->getAttribute('errorMessage'));
        $this->assertNotEmpty($build->getAttribute('endedAt'));
        $this->assertSame(0, $build->getAttribute('duration'));
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
    }

    public function testCreateEnqueuesDispatch(): void
    {
        $app = $this->storedApp();
        $target = $this->storedTarget();
        $git = new RecordingGit();

        $build = $this->builds(LocalBuilds::class)->create($app, $target, $this->request(), $git, 'appwrite', 'mobile-app');

        $this->assertCount(1, $this->broker->messages);
        $this->assertSame('dispatch', $this->broker->messages[0]['action']);
        $this->assertSame('build1', $this->broker->messages[0]['id']);
        $this->assertSame('project1', $this->broker->messages[0]['project']['$id']);

        $this->assertSame('waiting', $build->getAttribute('status'));
        $this->assertSame(1, $build->getAttribute('attempt'));
        $this->assertSame('orchestrator', $build->getAttribute('runner'));
        $this->assertSame('manual', $build->getAttribute('type'));
        $this->assertSame('app1', $build->getAttribute('appId'));
        $this->assertSame($app->getSequence(), $build->getAttribute('appInternalId'));
        $this->assertSame('target1', $build->getAttribute('targetId'));
        $this->assertSame($target->getSequence(), $build->getAttribute('targetInternalId'));
        $this->assertSame('android', $build->getAttribute('platform'));
        $this->assertSame('appwrite', $build->getAttribute('providerRepositoryOwner'));
        $this->assertSame('mobile-app', $build->getAttribute('providerRepositoryName'));
        $this->assertSame('http://gitea:3000/appwrite/mobile-app', $build->getAttribute('providerRepositoryUrl'));
        $this->assertSame('mobile', $build->getAttribute('providerRootDirectory'));
        $this->assertSame('d-4vcpu-8gb', $build->getAttribute('specification'));
        $this->assertSame(4, $build->getAttribute('cpus'));
        $this->assertSame(8192, $build->getAttribute('memory'));
        $this->assertSame(3600, $build->getAttribute('timeout'));
        $this->assertSame(1, $build->getAttribute('versionCode'));

        $stored = $this->dbForProject->getDocument('distributionApps', 'app1');
        $this->assertSame('build1', $stored->getAttribute('latestBuildId'));
        $this->assertSame($build->getSequence(), $stored->getAttribute('latestBuildInternalId'));
        $this->assertSame('waiting', $stored->getAttribute('latestBuildStatus'));
        $this->assertNotEmpty($stored->getAttribute('latestBuildCreatedAt'));

        $this->assertSame('queued', $git->checks[0]['state']);
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
        $this->assertSame([], $this->buildPlane->requests);
    }

    public function testCreateAllocatesTheNextVersionCode(): void
    {
        $builds = $this->builds(LocalBuilds::class);
        $app = $this->storedApp();
        $target = $this->storedTarget();

        $first = $builds->create($app, $target, $this->request('build1'), new RecordingGit(), 'appwrite', 'mobile-app');
        $second = $builds->create($app, $target, $this->request('build2'), new RecordingGit(), 'appwrite', 'mobile-app');

        $this->assertSame(1, $first->getAttribute('versionCode'));
        $this->assertSame(2, $second->getAttribute('versionCode'));
        $this->assertSame(2, $this->dbForProject->getDocument('distributionTargets', 'target1')->getAttribute('versionCode'));
    }

    public function testCreateRaisesTheCodeAboveTheLatest(): void
    {
        $build = $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(['versionCode' => 3]), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', latest: 41);

        $this->assertSame(42, $build->getAttribute('versionCode'));
        $this->assertSame(42, $this->dbForProject->getDocument('distributionTargets', 'target1')->getAttribute('versionCode'));
    }

    /**
     * @return \Iterator<string, array{?int}>
     */
    public static function latests(): \Iterator
    {
        yield 'no latest' => [null];
        yield 'a lower latest' => [5];
    }

    #[DataProvider('latests')]
    public function testCreateWithoutALatestKeepsTheCounter(?int $latest): void
    {
        $build = $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(['versionCode' => 10]), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', latest: $latest);

        $this->assertSame(11, $build->getAttribute('versionCode'));
        $this->assertSame(11, $this->dbForProject->getDocument('distributionTargets', 'target1')->getAttribute('versionCode'));
    }

    public function testInterleavedCreatesNeverShareAVersionCode(): void
    {
        $builds = $this->builds(LocalBuilds::class);
        $app = $this->storedApp();
        $target = $this->storedTarget();
        $second = null;
        $this->dbForProject->onIncrease = function () use ($builds, $app, $target, &$second): void {
            $second = $builds->create($app, $target, $this->request('build2'), new RecordingGit(), 'appwrite', 'mobile-app', latest: 5);
        };

        $first = $builds->create($app, $target, $this->request('build1'), new RecordingGit(), 'appwrite', 'mobile-app', latest: 5);

        $this->assertInstanceOf(Document::class, $second);
        $codes = [$first->getAttribute('versionCode'), $second->getAttribute('versionCode')];
        $this->assertNotSame($codes[0], $codes[1]);
        $this->assertGreaterThan(5, $codes[0]);
        $this->assertGreaterThan(5, $codes[1]);
        $this->assertSame(\max($codes), $this->dbForProject->getDocument('distributionTargets', 'target1')->getAttribute('versionCode'));
    }

    /**
     * @return \Iterator<string, array{int, ?int}>
     */
    public static function exhausted(): \Iterator
    {
        yield 'the counter' => [Builds::MAX_VERSION_CODE, null];
        yield 'the latest' => [10, Builds::MAX_VERSION_CODE];
    }

    #[DataProvider('exhausted')]
    public function testCreateRefusesACodeAboveTheMaximum(int $counter, ?int $latest): void
    {
        try {
            $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(['versionCode' => $counter]), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', latest: $latest);
            $this->fail('A version code above the maximum must be refused.');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_VERSION_INVALID, $error->getType());
        }

        $this->assertSame([], $this->dbForProject->documents['distributionBuilds'] ?? []);
    }

    public function testCreateAllocatesTheMaximumCode(): void
    {
        $build = $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(['versionCode' => 5]), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', latest: Builds::MAX_VERSION_CODE - 1);

        $this->assertSame(Builds::MAX_VERSION_CODE, $build->getAttribute('versionCode'));
    }

    public function testManualStrategyRequiresACode(): void
    {
        $builds = $this->builds(LocalBuilds::class);
        $app = $this->storedApp();
        $target = $this->storedTarget(['versionStrategy' => 'manual', 'versionCode' => 9]);

        try {
            $builds->create($app, $target, $this->request(), new RecordingGit(), 'appwrite', 'mobile-app');
            $this->fail('A manual build without a version code must be refused.');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_VERSION_INVALID, $error->getType());
        }
        $this->assertSame([], $this->dbForProject->documents['distributionBuilds'] ?? []);

        $build = $builds->create($app, $target, $this->request('build1', ['versionCode' => 99]), new RecordingGit(), 'appwrite', 'mobile-app', latest: 500);

        $this->assertSame(99, $build->getAttribute('versionCode'));
        $this->assertSame(9, $this->dbForProject->getDocument('distributionTargets', 'target1')->getAttribute('versionCode'));
    }

    public function testCreateStoresAnIosCodeAsTheBuildNumber(): void
    {
        $build = $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(['platform' => 'ios', 'identifier' => 'io.appwrite.example']), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app');

        $this->assertSame('1', $build->getAttribute('buildNumber'));
        $this->assertNull($build->getAttribute('versionCode'));
        $this->assertSame('pool', $build->getAttribute('runner'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>, array<string, bool>, bool, string}>
     */
    public static function refusals(): \Iterator
    {
        yield 'disabled app' => [['enabled' => false], [], [], true, Exception::GENERAL_ARGUMENT_INVALID];
        yield 'no installation' => [['installationId' => ''], [], [], true, Exception::DISTRIBUTION_REPOSITORY_MISSING];
        yield 'no repository' => [['providerRepositoryId' => ''], [], [], true, Exception::DISTRIBUTION_REPOSITORY_MISSING];
        yield 'provider without archives' => [[], [], [], false, Exception::DISTRIBUTION_REPOSITORY_MISSING];
        yield 'rest disabled' => [[], [], ['rest' => false], true, Exception::GENERAL_API_DISABLED];
        yield 'unknown specification' => [[], ['specification' => 's-1vcpu-512mb'], [], true, Exception::GENERAL_ARGUMENT_INVALID];
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed> $target
     * @param array<string, bool> $apis
     */
    #[DataProvider('refusals')]
    public function testCreateRefusesDisabledAppMissingRepositoryAndRestDisabled(array $app, array $target, array $apis, bool $archives, string $type): void
    {
        $this->project = new Document([...$this->project->getArrayCopy(), 'apis' => $apis]);

        try {
            $this->builds(LocalBuilds::class)->create($this->storedApp($app), $this->storedTarget($target), $this->request(), new RecordingGit(archived: $archives), 'appwrite', 'mobile-app');
            $this->fail('The build must be refused.');
        } catch (Exception $error) {
            $this->assertSame($type, $error->getType());
        }

        $this->assertSame([], $this->dbForProject->documents['distributionBuilds'] ?? []);
        $this->assertSame(0, $this->dbForProject->getDocument('distributionTargets', 'target1')->getAttribute('versionCode'));
        $this->assertSame([], $this->broker->messages);
    }

    public function testCreateLogsPreflightIssuesWithoutFailing(): void
    {
        $checks = new ScriptedChecks([
            $this->issue('error', 'connection_missing', 'The target has no store connection.', 'Connect a Google Play account.'),
            $this->issue('warning', 'signing_identity_missing', 'The target has no signing identity.', 'Upload a keystore.'),
        ]);

        $build = $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', checks: $checks);

        $logs = \array_values($this->dbForProject->documents['distributionLogs']);
        $this->assertSame([
            ['build1', $build->getSequence(), 0, 0, 'source', "error connection_missing: The target has no store connection. Connect a Google Play account.\n"],
            ['build1', $build->getSequence(), 0, 1, 'source', "warning signing_identity_missing: The target has no signing identity. Upload a keystore.\n"],
        ], \array_map(fn (Document $log): array => [
            $log->getAttribute('buildId'),
            $log->getAttribute('buildInternalId'),
            $log->getAttribute('sequence'),
            $log->getAttribute('chunk'),
            $log->getAttribute('stage'),
            $log->getAttribute('content'),
        ], $logs));
        $this->assertSame(\array_sum(\array_map(fn (Document $log): int => \strlen($log->getAttribute('content')), $logs)), $build->getAttribute('logsSize'));
        $this->assertSame([['internal', false]], $checks->calls->getArrayCopy());
        $this->assertSame('waiting', $build->getAttribute('status'));
        $this->assertCount(1, $this->broker->messages);
    }

    public function testCreateIgnoresAFailingPreflight(): void
    {
        $checks = new ScriptedChecks(failure: new Exception(Exception::DISTRIBUTION_TRACK_INVALID));

        $build = $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', checks: $checks);

        $this->assertSame([], $this->dbForProject->documents['distributionLogs'] ?? []);
        $this->assertSame(0, $build->getAttribute('logsSize'));
        $this->assertSame('waiting', $build->getAttribute('status'));
        $this->assertCount(1, $this->broker->messages);
    }

    public function testPreflightOnATargetWithoutATrackUsesTheProviderDefault(): void
    {
        $checks = new ScriptedChecks([$this->issue('warning', 'track_unknown', 'No track is set.', 'Choose a track.')]);

        $this->builds(LocalBuilds::class)->create($this->storedApp(), $this->storedTarget(['track' => '']), $this->request(), new RecordingGit(), 'appwrite', 'mobile-app', checks: $checks);

        $this->assertSame([[Stores::testing(Provider::Google), false]], $checks->calls->getArrayCopy());
        $this->assertSame('internal', $checks->calls[0][0]);
        $this->assertCount(1, $this->dbForProject->documents['distributionLogs']);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function silences(): \Iterator
    {
        yield 'statuses off' => [['providerStatuses' => false], []];
        yield 'silent mode' => [['providerSilentMode' => true], []];
        yield 'no commit' => [[], ['providerCommitHash' => '']];
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed> $build
     */
    #[DataProvider('silences')]
    public function testReportIsSilentWhenStatusesOffOrSilentMode(array $app, array $build): void
    {
        $git = new RecordingGit();

        $this->assertTrue($this->builds(LocalBuilds::class)->report($this->app($app), new Document([...$this->build()->getArrayCopy(), ...$build]), $git));
        $this->assertSame([], $git->checks);
    }

    /**
     * @return \Iterator<string, array{string, string, string}>
     */
    public static function states(): \Iterator
    {
        yield 'waiting' => ['waiting', 'queued', 'Queued...'];
        yield 'processing' => ['processing', 'pending', 'Building...'];
        yield 'building' => ['building', 'pending', 'Building...'];
        yield 'signing' => ['signing', 'pending', 'Signing...'];
        yield 'ready' => ['ready', 'success', 'Build succeeded.'];
        yield 'failed' => ['failed', 'failure', 'Build failed.'];
        yield 'canceled' => ['canceled', 'cancelled', 'Build canceled.'];
    }

    #[DataProvider('states')]
    public function testReportMapsStatusToCheckState(string $status, string $state, string $title): void
    {
        $git = new RecordingGit();

        $this->assertTrue($this->builds(LocalBuilds::class)->report($this->app(), new Document([...$this->build()->getArrayCopy(), 'status' => $status]), $git));

        $this->assertCount(1, $git->checks);
        $this->assertSame($state, $git->checks[0]['state']);
        $this->assertSame($title, $git->checks[0]['title']);
    }

    public function testReportNamesTheCheckByAppAndPlatform(): void
    {
        $git = new RecordingGit();
        $this->project = new Document([...$this->project->getArrayCopy(), 'name' => 'Mobile']);

        $this->builds(LocalBuilds::class)->report($this->app(), new Document([...$this->build()->getArrayCopy(), 'status' => 'ready', 'duration' => 98]), $git);

        $this->assertSame([
            'owner' => 'appwrite',
            'repository' => 'mobile-app',
            'commit' => 'abc1234def5678',
            'state' => 'success',
            'name' => 'Example',
            'title' => 'Build succeeded.',
            'summary' => "- Commit abc1234 on main: Ship it\n- Platform android\n- Version 1.2.3 (7)\n- Built in 98s",
            'detailsUrl' => 'https://console.example.test/projects/project1/distribution/app1/builds/build1',
            'suite' => 'Mobile',
            'key' => 'project1/distributionApps/app1/android',
            'suiteKey' => 'project1',
        ], $git->checks[0]);
    }

    public function testReportReturnsFalseWhenTheProviderFails(): void
    {
        $this->assertFalse($this->builds(LocalBuilds::class)->report($this->app(), $this->build(), new RecordingGit(failure: new \RuntimeException('Provider unavailable'))));
    }

    public function testAbortCancelsTheBuildJob(): void
    {
        $build = $this->storedBuild([
            'status' => 'building',
            'stage' => 'build',
            'jobId' => 'dist-build-job',
            'startedAt' => DateTime::addSeconds(new \DateTime(), -100),
        ]);

        $canceled = $this->builds(LocalBuilds::class)->abort($build);

        $this->assertSame('canceled', $canceled->getAttribute('status'));
        $this->assertSame(Builds::ERROR_BUILD_CANCELED, $canceled->getAttribute('errorCode'));
        $this->assertSame(Builds::message(Builds::ERROR_BUILD_CANCELED), $canceled->getAttribute('errorMessage'));
        $this->assertNotEmpty($canceled->getAttribute('endedAt'));
        $this->assertEqualsWithDelta(100, $canceled->getAttribute('duration'), 1);

        $this->assertCount(1, $this->buildPlane->requests);
        $this->assertSame('DELETE', $this->buildPlane->requests[0]->getMethod());
        $this->assertSame('/v1/jobs/dist-build-job', $this->buildPlane->requests[0]->getUri()->getPath());
        $this->assertSame([], $this->signingPlane->requests);
        $this->assertSame([['reconcile', 'build1']], \array_map(fn (array $message): array => [$message['action'], $message['id']], $this->broker->messages));
    }

    public function testAbortCancelsTheJobAndTheInFlightArtifact(): void
    {
        $artifact = $this->stored(new Document([
            ...$this->artifact('aab')->getArrayCopy(),
            'source' => 'build',
            'buildId' => 'build1',
            'metadata' => ['job' => ['stage' => Builds::STAGE_SIGN, 'id' => 'dist-sign-job', 'attempt' => 1]],
        ]));
        (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $artifact, 1);
        $build = $this->storedBuild(['status' => 'signing', 'stage' => 'sign', 'jobId' => 'dist-build-job', 'artifactId' => 'artifact1', 'duration' => 240]);

        $canceled = $this->builds(LocalBuilds::class)->abort($build);

        $this->assertSame('canceled', $canceled->getAttribute('status'));
        $this->assertSame(240, $canceled->getAttribute('duration'));

        $stored = $this->dbForProject->getDocument('distributionArtifacts', 'artifact1');
        $this->assertSame('invalid', $stored->getAttribute('status'));
        $this->assertSame(Builds::ERROR_BUILD_CANCELED, $stored->getAttribute('errorCode'));
        $this->assertFalse($this->schedule('artifact1')->getAttribute('active'));

        $this->assertSame([], $this->buildPlane->requests);
        $this->assertCount(1, $this->signingPlane->requests);
        $this->assertSame('/v1/jobs/dist-sign-job', $this->signingPlane->requests[0]->getUri()->getPath());
    }

    public function testAbortKeepsTheScheduleRow(): void
    {
        $build = $this->storedBuild(['status' => 'building', 'stage' => 'build', 'jobId' => 'dist-build-job']);
        (new Schedules($this->dbForPlatform))->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $build, 1);
        $this->buildPlane = new Orchestrator(['DELETE' => 500]);

        $canceled = $this->builds(LocalBuilds::class)->abort($build);

        $this->assertSame('canceled', $canceled->getAttribute('status'));
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
        $this->assertSame(1, $this->schedule('build1')->getAttribute('interval'));
    }

    public function testAbortLeavesATerminalBuild(): void
    {
        $build = $this->storedBuild(['status' => 'ready', 'stage' => 'sign', 'jobId' => 'dist-build-job', 'artifactId' => 'artifact1']);

        $result = $this->builds(LocalBuilds::class)->abort($build);

        $this->assertSame('ready', $result->getAttribute('status'));
        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame([], $this->signingPlane->requests);
        $this->assertSame([], $this->broker->messages);
        $this->assertTrue($this->schedule('build1')->isEmpty());
    }

    public function testAbortReturnsTheBuildAConcurrentWriteConcluded(): void
    {
        $build = $this->storedBuild(['status' => 'signing', 'stage' => 'sign', 'jobId' => 'dist-build-job', 'artifactId' => 'artifact1']);
        $this->dbForProject->onUpdates = function (): void {
            $this->dbForProject->updateDocument('distributionBuilds', 'build1', new Document([
                'status' => 'ready',
                'endedAt' => DateTime::now(),
            ]));
        };

        $result = $this->builds(LocalBuilds::class)->abort($build);

        $this->assertSame('ready', $result->getAttribute('status'));
        $this->assertNotEmpty($result->getAttribute('endedAt'));
        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame([], $this->signingPlane->requests);
        $this->assertSame([], $this->broker->messages);
    }

    public function testRedispatchStopsAfterThreeAttempts(): void
    {
        $this->storedApp();
        $this->storedTarget();
        $builds = $this->builds(LocalBuilds::class);
        $build = $builds->dispatch($this->storedBuild(['toolchain' => ['cacheFailed' => true]]), new RecordingGit());

        foreach ([2, 3] as $attempt) {
            $build = $builds->redispatch($this->started(), new RecordingGit());

            $this->assertSame($attempt, $build->getAttribute('attempt'));
            $this->assertSame(Builds::id($this->project, $build, Builds::STAGE_BUILD, $attempt), $build->getAttribute('jobId'));
            $this->assertSame('processing', $build->getAttribute('status'));
            $this->assertNull($build->getAttribute('startedAt'));
            $this->assertNull($build->getAttribute('redispatchAt'));
            $this->assertTrue($build->getAttribute('toolchain')['cacheFailed']);
        }

        $spent = $builds->redispatch($this->started(), new RecordingGit());

        $this->assertSame(3, $spent->getAttribute('attempt'));
        $this->assertSame('building', $spent->getAttribute('status'));
        $this->assertCount(3, $this->buildPlane->requests);
    }

    public function testRedispatchLeavesATerminalBuild(): void
    {
        $build = $this->builds(LocalBuilds::class)->redispatch($this->storedBuild(['status' => 'canceled', 'jobId' => 'dist-build-job']), new RecordingGit());

        $this->assertSame('canceled', $build->getAttribute('status'));
        $this->assertSame(1, $build->getAttribute('attempt'));
        $this->assertSame([], $this->buildPlane->requests);
    }

    public function testClaimReturnsTheLeasePayload(): void
    {
        $lease = $this->claimable();
        $app = $this->dbForProject->getDocument('distributionApps', 'app1');
        $this->variable('project', '', 'API_URL', 'https://api.example.test');
        $this->variable(RESOURCE_TYPE_DISTRIBUTION_APPS, (string) $app->getSequence(), 'DISTRIBUTION_IDENTIFIER', 'io.appwrite.spoofed');

        $assignment = $this->builds(LocalBuilds::class)->claim($lease, 'lease-token');

        $this->assertIsArray($assignment);
        $this->assertSame(self::LEASE, $assignment['leaseId']);
        $this->assertSame('lease-token', $assignment['token']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_RFC3339_EXTENDED, $assignment['expiresAt']), 'The expiry is RFC 3339.');
        $this->assertSame((new \DateTimeImmutable('2030-01-01 00:00:00.000'))->getTimestamp(), (new \DateTimeImmutable($assignment['expiresAt']))->getTimestamp());
        $this->assertSame('https://appwrite.test/v1', $assignment['endpoint']);
        $this->assertSame(['project' => 'project1'], $assignment['query']);
        $this->assertSame('build1', $assignment['buildId']);
        $this->assertSame(1, $assignment['attempt']);
        $this->assertSame(900, $assignment['timeout'], 'A build without a timeout takes its target\'s.');
        $this->assertSame('ios', $assignment['platform']);
        $this->assertSame('flutter', $assignment['framework']);
        $this->assertSame(['rootDirectory' => 'mobile', 'commit' => 'abc1234def5678'], $assignment['source']);

        $environment = $assignment['environment'];
        $this->assertSame('io.appwrite.example', $environment['DISTRIBUTION_IDENTIFIER'], 'A variable cannot replace the target identifier.');
        $this->assertSame('https://api.example.test', $environment['API_URL']);
        $this->assertSame('{"scheme":"Runner","xcode":"16.2"}', $environment['DISTRIBUTION_CONFIGURATION']);
        $this->assertSame('Runner', $environment['DISTRIBUTION_SCHEME']);
        $this->assertSame('abc1234def5678', $environment['APPWRITE_VCS_COMMIT_HASH']);
        $this->assertSame('mobile-app', $environment['APPWRITE_VCS_REPOSITORY_NAME']);
        $this->assertSame('true', $environment['CI']);
        $this->assertArrayNotHasKey('DISTRIBUTION_VERSION_NAME', $environment, 'The agent sets the version from the lease.');

        $this->assertSame([
            'install' => Config::getParam('distribution')['frameworks']['flutter']['ios']['install'],
            'build' => 'flutter build ipa --release --flavor production',
        ], $assignment['commands']);
        $this->assertSame([
            'certificate' => 'cDEyLWJ5dGVz',
            'password' => 'p12-password',
            'profiles' => ['io.appwrite.example' => 'cHJvZmlsZQ==', 'io.appwrite.example.widget' => 'd2lkZ2V0'],
            'teamId' => 'ABCDE12345',
        ], $assignment['signing'], 'Only profiles keyed by a bundle ID reach the runner.');
        $this->assertSame(['name' => '', 'code' => 42], $assignment['version'], 'An empty version name is sent as stored.');

        $this->assertSame('processing', $this->dbForProject->getDocument('distributionBuilds', 'build1')->getAttribute('status'));
    }

    public function testClaimFallsBackToTheConfiguredTimeoutAndOmitsAMissingScheme(): void
    {
        $lease = $this->claimable();
        $builds = $this->builds(LocalBuilds::class);
        $app = $this->dbForProject->getDocument('distributionApps', 'app1');
        $target = $this->dbForProject->updateDocument('distributionTargets', 'target1', new Document([
            'timeout' => 0,
            'buildCommand' => '',
            'configuration' => null,
        ]));
        $build = $this->dbForProject->getDocument('distributionBuilds', 'build1');
        $signingIdentity = $this->dbForProject->getDocument('distributionSigningIdentities', 'identity1');

        $assignment = $builds->claim($lease, 'lease-token');
        $named = $builds->assignmentPayload($app, $target, new Document([...$build->getArrayCopy(), 'versionName' => '2.4.0']), $lease, $signingIdentity);

        $this->assertIsArray($assignment);
        $this->assertSame(3600, $assignment['timeout'], 'Without a build or target timeout the configured default applies.');
        $this->assertSame('{}', $assignment['environment']['DISTRIBUTION_CONFIGURATION']);
        $this->assertArrayNotHasKey('DISTRIBUTION_SCHEME', $assignment['environment']);
        $this->assertSame(Config::getParam('distribution')['frameworks']['flutter']['ios']['build'], $assignment['commands']['build']);
        $this->assertSame(['name' => '2.4.0', 'code' => 42], $named['version']);
    }

    public static function stale(): \Iterator
    {
        yield 'a later attempt' => [['attempt' => 2], []];
        yield 'another job' => [['jobId' => 'dist-build-job'], []];
        yield 'a canceled build' => [['status' => 'canceled'], []];
        yield 'a build not yet in its build stage' => [['stage' => ''], []];
        yield 'an Android identity' => [[], ['platform' => 'android']];
    }

    /**
     * @param array<string, mixed> $build
     * @param array<string, mixed> $identity
     */
    #[DataProvider('stale')]
    public function testClaimRefusesAStaleLease(array $build, array $identity): void
    {
        $lease = $this->claimable($build, $identity);
        $status = $this->dbForProject->getDocument('distributionBuilds', 'build1')->getAttribute('status');

        $this->assertNull($this->builds(LocalBuilds::class)->claim($lease, 'lease-token'));
        $this->assertSame($status, $this->dbForProject->getDocument('distributionBuilds', 'build1')->getAttribute('status'));
    }

    public function testJobIdsCarryTheOrchestratorPrefix(): void
    {
        $build = $this->build();

        foreach ([Builds::STAGE_INSPECT, Builds::STAGE_SIGN, Builds::STAGE_BUILD] as $stage) {
            $this->assertStringStartsWith(Builds::JOB, Builds::id($this->project, $build, $stage));
        }

        $lease = Leases::id($this->project, $build, 1);
        $this->assertStringStartsNotWith(Builds::JOB, $lease, 'A lease id never passes for a build-plane job.');
        $this->assertStringStartsNotWith(Builds::JOB, Leases::artifact($lease));
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function schemes(): \Iterator
    {
        yield 'https' => ['enabled', 'https://cloud.example.test/v1'];
        yield 'http' => ['disabled', 'http://cloud.example.test/v1'];
    }

    #[DataProvider('schemes')]
    public function testDispatchQueuesAnIosLeaseWhenARunnerIsOnline(string $forceHttps, string $endpoint): void
    {
        $this->setEnvironment('_APP_DOMAIN', 'cloud.example.test');
        $this->setEnvironment('_APP_OPTIONS_FORCE_HTTPS', $forceHttps);
        $this->runner(['labels' => ['xcode-16.2', 'fake']]);
        $build = $this->iosBuild();

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($build, new RecordingGit());

        $leaseId = Leases::id($this->project, $build, 1);
        $lease = $this->dbForPlatform->getDocument('distributionLeases', $leaseId);
        $this->assertSame([], $this->buildPlane->requests);
        $this->assertSame('waiting', $dispatched->getAttribute('status'));
        $this->assertSame('build', $dispatched->getAttribute('stage'));
        $this->assertSame('pool', $dispatched->getAttribute('runner'));
        $this->assertSame($leaseId, $dispatched->getAttribute('jobId'));
        $this->assertNotEmpty($dispatched->getAttribute('dispatchedAt'));
        $this->assertSame('queued', $lease->getAttribute('status'));
        $this->assertSame('7', $lease->getAttribute('projectInternalId'));
        $this->assertSame('build1', $lease->getAttribute('buildId'));
        $this->assertSame(1, $lease->getAttribute('attempt'));
        $this->assertSame(['xcode-16.2'], $lease->getAttribute('labels'));
        $this->assertSame($endpoint, $lease->getAttribute('endpoint'));
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
    }

    public function testDispatchKeepsAnExistingIosLease(): void
    {
        $this->runner();
        $builds = $this->builds(LocalBuilds::class);
        $first = $builds->dispatch($this->iosBuild(), new RecordingGit());
        $dispatchedAt = '2020-01-01T00:00:00.000+00:00';
        $this->dbForProject->updateDocument('distributionBuilds', 'build1', new Document(['dispatchedAt' => $dispatchedAt]));

        $second = $builds->dispatch($first, new RecordingGit());

        $this->assertSame($first->getAttribute('jobId'), $second->getAttribute('jobId'));
        $this->assertSame($dispatchedAt, $second->getAttribute('dispatchedAt'), 'the same attempt keeps its lease and deadline');
        $this->assertSame('waiting', $second->getAttribute('status'));
        $this->assertCount(1, $this->dbForPlatform->documents['distributionLeases']);
    }

    public function testDispatchNamesTheIosLeaseBeforeQueuingIt(): void
    {
        $this->runner();
        $build = $this->iosBuild();
        $leaseId = Leases::id($this->project, $build, 1);
        $named = null;
        $this->dbForPlatform->onCreate = function (string $collection) use (&$named): void {
            if ($collection === 'distributionLeases') {
                $named = $this->dbForProject->getDocument('distributionBuilds', 'build1')->getAttribute('jobId');
            }
        };

        $this->builds(LocalBuilds::class)->dispatch($build, new RecordingGit());

        $this->assertSame($leaseId, $named, 'a runner claiming the new lease finds the build already naming it');
    }

    public function testDispatchQueuesTheLeaseTheBuildAlreadyNames(): void
    {
        $this->runner();
        $build = $this->iosBuild();
        $leaseId = Leases::id($this->project, $build, 1);
        $dispatchedAt = '2020-01-01T00:00:00.000+00:00';
        $build = $this->dbForProject->updateDocument('distributionBuilds', 'build1', new Document([
            'stage' => 'build',
            'jobId' => $leaseId,
            'dispatchedAt' => $dispatchedAt,
        ]));

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($build, new RecordingGit());

        $this->assertSame($dispatchedAt, $dispatched->getAttribute('dispatchedAt'), 'recovering the lost lease row keeps the attempt');
        $this->assertSame('queued', $this->dbForPlatform->getDocument('distributionLeases', $leaseId)->getAttribute('status'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function unsignable(): \Iterator
    {
        yield 'no identity' => [['signingIdentityId' => ''], [], 'The target has no signing identity.'];
        yield 'android identity' => [[], ['platform' => 'android'], "Signing identity 'identity1' is not an iOS signing identity"];
        yield 'no profile for the bundle ID' => [[], ['secret' => ['profiles' => ['io.appwrite.other' => 'cHJvZmlsZQ==']]], "Signing identity 'identity1' is not an iOS signing identity with an App Store profile for io.appwrite.example."];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $identity
     */
    #[DataProvider('unsignable')]
    public function testDispatchFailsAnIosBuildWithoutASigningIdentity(array $target, array $identity, string $message): void
    {
        $this->runner();

        $failed = $this->builds(LocalBuilds::class)->dispatch($this->iosBuild($target, identity: $identity), new RecordingGit());

        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(Builds::ERROR_BUILD_INVALID, $failed->getAttribute('errorCode'));
        $this->assertStringContainsString($message, (string) $failed->getAttribute('errorMessage'));
        $this->assertSame([], $this->dbForPlatform->documents['distributionLeases'] ?? []);
        $this->assertTrue($this->schedule('build1')->getAttribute('active'));
    }

    public function testDispatchFailsAnIosBuildWithoutAVersionName(): void
    {
        $this->runner();

        $failed = $this->builds(LocalBuilds::class)->dispatch($this->iosBuild(build: ['versionName' => '']), new RecordingGit());

        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(Builds::ERROR_BUILD_INVALID, $failed->getAttribute('errorCode'));
        $this->assertSame("iOS builds need a version name until the runner agent supports keeping the project's version; set versionName on the build.", $failed->getAttribute('errorMessage'));
        $this->assertSame('', $failed->getAttribute('jobId'));
        $this->assertSame([], $this->dbForPlatform->documents['distributionLeases'] ?? []);
    }

    public function testDispatchFailsAnIosBuildWhenNoRunnerHasTheLabels(): void
    {
        $this->runner(['labels' => ['xcode-15.4']]);
        $this->runner(['status' => 'offline', 'labels' => ['xcode-16.2']]);

        $failed = $this->builds(LocalBuilds::class)->dispatch($this->iosBuild(), new RecordingGit());

        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(Builds::ERROR_RUNNER_UNAVAILABLE, $failed->getAttribute('errorCode'));
        $this->assertSame([], $this->dbForPlatform->documents['distributionLeases'] ?? []);
    }

    public function testDispatchQueuesAnIosLeaseForADrainingRunner(): void
    {
        $this->runner(['status' => 'draining']);

        $dispatched = $this->builds(LocalBuilds::class)->dispatch($this->iosBuild(), new RecordingGit());

        $this->assertSame('waiting', $dispatched->getAttribute('status'));
        $this->assertCount(1, $this->dbForPlatform->documents['distributionLeases']);
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function abandoned(): \Iterator
    {
        yield 'uploading' => ['uploading', 'invalid'];
        yield 'processing' => ['processing', 'invalid'];
        yield 'ready' => ['ready', 'ready'];
    }

    #[DataProvider('abandoned')]
    public function testCancelInvalidatesTheAbandonedLeaseArtifact(string $status, string $expected): void
    {
        $artifactId = Leases::artifact('lease1');
        $otherId = Leases::artifact('lease2');
        $this->dbForProject->createDocument('distributionArtifacts', new Document(['$id' => $artifactId, 'status' => $status, 'errorCode' => '']));
        $this->dbForProject->createDocument('distributionArtifacts', new Document(['$id' => $otherId, 'status' => 'uploading', 'errorCode' => '']));
        $build = new Document(['$id' => 'build1', '$collection' => 'distributionBuilds', 'runner' => 'pool', 'stage' => 'build', 'jobId' => 'lease1']);

        $this->builds(LocalBuilds::class)->cancel($build);

        $artifact = $this->dbForProject->getDocument('distributionArtifacts', $artifactId);
        $this->assertSame($expected, $artifact->getAttribute('status'));
        $this->assertSame($expected === 'invalid' ? Builds::ERROR_BUILD_FAILED : '', $artifact->getAttribute('errorCode'));
        if ($expected === 'invalid') {
            $this->assertSame(Builds::message(Builds::ERROR_BUILD_FAILED), $artifact->getAttribute('errorMessage'));
        }
        $this->assertSame('uploading', $this->dbForProject->getDocument('distributionArtifacts', $otherId)->getAttribute('status'), 'another lease keeps its upload');
    }

    /**
     * @template T of ExposedBuilds
     * @param class-string<T> $class
     * @return T
     */
    private function builds(string $class): ExposedBuilds
    {
        return new $class(
            new Jobs($this->buildPlane),
            new Jobs($this->signingPlane),
            $this->dbForProject,
            $this->dbForPlatform,
            $this->project,
            ['apiHostname' => 'appwrite', 'consoleUrl' => 'https://console.example.test'],
            new DistributionBuildPublisher($this->broker, new Queue('v1-distribution-builds')),
            $this->cache,
        );
    }

    private function artifact(string $type): Document
    {
        return new Document([
            '$id' => 'artifact1',
            '$collection' => 'distributionArtifacts',
            'appId' => 'app1',
            'type' => $type,
            'status' => 'processing',
            'identifier' => 'io.appwrite.example',
            'versionCode' => 57,
            'metadata' => ['uploadId' => 'upload1'],
        ]);
    }

    private function target(): Document
    {
        return new Document(['$id' => 'target1', 'platform' => 'android', 'identifier' => 'io.appwrite.example']);
    }

    private function stored(Document $artifact): Document
    {
        return $this->dbForProject->createDocument('distributionArtifacts', $artifact);
    }

    private function inspecting(): Document
    {
        return $this->builds(LocalBuilds::class)->inspect($this->stored($this->artifact('aab')));
    }

    /**
     * A waiting iOS build whose current attempt is the returned lease, as a runner just claimed it.
     *
     * @param array<string, mixed> $build
     * @param array<string, mixed> $identity
     */
    private function claimable(array $build = [], array $identity = []): Document
    {
        $this->storedApp();
        $this->storedTarget([
            'platform' => 'ios',
            'signingIdentityId' => 'identity1',
            'timeout' => 900,
            'buildCommand' => 'flutter build ipa --release --flavor production',
            'configuration' => ['scheme' => 'Runner', 'xcode' => '16.2'],
        ]);
        $this->dbForProject->createDocument('distributionSigningIdentities', new Document([
            '$id' => 'identity1',
            'platform' => 'ios',
            'mode' => 'imported',
            'teamId' => 'ABCDE12345',
            'secret' => [
                'certificate' => 'cDEyLWJ5dGVz',
                'password' => 'p12-password',
                'profiles' => [
                    'io.appwrite.example' => 'cHJvZmlsZQ==',
                    'io.appwrite.example.widget' => 'd2lkZ2V0',
                    '.hidden' => 'aGlkZGVu',
                    'io.appwrite/escape' => 'ZXNjYXBl',
                    'io appwrite' => 'c3BhY2U=',
                    '' => 'ZW1wdHk=',
                ],
            ],
            ...$identity,
        ]));
        $this->storedBuild([
            'platform' => 'ios',
            'runner' => 'pool',
            'stage' => 'build',
            'jobId' => self::LEASE,
            'timeout' => 0,
            'versionName' => '',
            'versionCode' => null,
            'buildNumber' => '42',
            ...$build,
        ]);

        return new Document([
            '$id' => self::LEASE,
            'projectId' => 'project1',
            'projectInternalId' => '7',
            'buildId' => 'build1',
            'attempt' => 1,
            'status' => 'leased',
            'endpoint' => 'https://appwrite.test/v1',
            'expiresAt' => '2030-01-01 00:00:00.000',
        ]);
    }

    private function schedule(string $resourceId): Document
    {
        return $this->dbForPlatform->findOne('schedules', [
            Query::equal('resourceId', [$resourceId]),
        ]);
    }

    private function token(): string
    {
        $headers = $this->signingPlane->job()['artifacts'][0]['headers'] ?? [];

        return \substr($headers['authorization'] ?? '', \strlen('Bearer '));
    }

    private function assertResult(mixed $artifact): void
    {
        $this->assertInstanceOf(ReadArtifact::class, $artifact);
        $this->assertSame('result', $artifact->id);
        $this->assertSame('output/result.json', $artifact->in);
        $this->assertSame('json', $artifact->format?->value);
        $this->assertSame('job', $artifact->depends);
    }

    private function assertSigningDownload(mixed $artifact, string $token): void
    {
        $this->assertInstanceOf(DownloadArtifact::class, $artifact);
        $this->assertSame('signing', $artifact->id);
        $this->assertSame('signing/bundle.json', $artifact->out);
        $this->assertSame(60, $artifact->timeoutSeconds);
        $this->assertSame(['authorization' => 'Bearer ' . $token], $artifact->headers);
        $this->assertSame('/v1/distribution/apps/app1/builds/artifact1/signing', \parse_url($artifact->in, PHP_URL_PATH));
        $this->assertSame('project=project1', \parse_url($artifact->in, PHP_URL_QUERY));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertMeta(array $payload, string $stage): void
    {
        $this->assertSame([
            'projectId' => 'project1',
            'appId' => 'app1',
            'artifactId' => 'artifact1',
            'stage' => $stage,
            'attempt' => '1',
        ], $payload['meta']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertCallback(array $payload): void
    {
        $callback = $payload['callback'];
        $this->assertInstanceOf(Callback::class, $callback);
        $this->assertSame('http://appwrite/v1/distribution/events?project=project1', $callback->url);
        $this->assertSame('signing-callback-secret', $callback->key);
        $this->assertSame([
            'orchestrator.job.start',
            'orchestrator.job.exit',
            'orchestrator.job.complete',
            'orchestrator.job.artifact',
        ], $callback->toArray()['events']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function app(array $attributes = []): Document
    {
        return new Document([
            '$id' => 'app1',
            '$sequence' => '1',
            'name' => 'Example',
            'enabled' => true,
            'framework' => 'flutter',
            'installationId' => 'installation1',
            'providerRepositoryId' => '42',
            'providerRootDirectory' => 'mobile',
            'providerSilentMode' => false,
            'providerStatuses' => true,
            ...$attributes,
        ]);
    }

    private function buildTarget(): Document
    {
        return new Document([
            '$id' => 'target1',
            'appId' => 'app1',
            'platform' => 'android',
            'identifier' => 'io.appwrite.example',
            'versionStrategy' => 'automatic',
            'versionCode' => 0,
            'track' => 'internal',
            'specification' => '',
            'timeout' => 0,
            'installCommand' => '',
            'buildCommand' => 'flutter build appbundle --release --flavor production',
            'outputPath' => '',
            'configuration' => ['flavor' => 'production'],
        ]);
    }

    private function build(): Document
    {
        return new Document([
            '$id' => 'build1',
            '$collection' => 'distributionBuilds',
            'appId' => 'app1',
            'targetId' => 'target1',
            'platform' => 'android',
            'type' => 'manual',
            'status' => 'waiting',
            'stage' => '',
            'attempt' => 1,
            'runner' => 'orchestrator',
            'jobId' => '',
            'cpus' => 4,
            'memory' => 8192,
            'timeout' => 3600,
            'versionName' => '1.2.3',
            'versionCode' => 7,
            'providerRepositoryOwner' => 'appwrite',
            'providerRepositoryName' => 'mobile-app',
            'providerRepositoryUrl' => 'http://gitea:3000/appwrite/mobile-app',
            'providerRootDirectory' => 'mobile',
            'providerBranch' => 'main',
            'providerBranchUrl' => 'http://gitea:3000/appwrite/mobile-app/src/branch/main',
            'providerCommitHash' => 'abc1234def5678',
            'providerCommitMessage' => "Ship it\n\nWith a body",
            'providerCommitUrl' => 'http://gitea:3000/appwrite/mobile-app/commit/abc1234def5678',
            'providerCommitAuthor' => 'Ada',
            'providerCommitAuthorUrl' => 'http://gitea:3000/ada',
        ]);
    }

    /**
     * The build a route hands to create().
     *
     * @param array<string, mixed> $attributes
     */
    private function request(string $id = 'build1', array $attributes = []): Document
    {
        return new Document([
            '$id' => $id,
            'type' => 'manual',
            'providerBranch' => 'main',
            'providerCommitHash' => 'abc1234def5678',
            'providerCommitMessage' => 'Ship it',
            'versionName' => '1.2.3',
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function storedApp(array $attributes = []): Document
    {
        $app = $this->app($attributes)->getArrayCopy();
        unset($app['$sequence']);

        return $this->dbForProject->createDocument('distributionApps', new Document($app));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function storedTarget(array $attributes = []): Document
    {
        return $this->dbForProject->createDocument('distributionTargets', new Document([...$this->buildTarget()->getArrayCopy(), ...$attributes]));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function storedBuild(array $attributes = []): Document
    {
        return $this->dbForProject->createDocument('distributionBuilds', new Document([...$this->build()->getArrayCopy(), ...$attributes]));
    }

    /**
     * The stored build, as the start event of its job leaves it, with a re-dispatch asked for.
     */
    private function started(): Document
    {
        return $this->dbForProject->updateDocument('distributionBuilds', 'build1', new Document([
            'status' => 'building',
            'startedAt' => DateTime::now(),
            'redispatchAt' => DateTime::now(),
        ]));
    }

    private function variable(string $resourceType, string $resourceInternalId, string $key, string $value): void
    {
        $this->dbForProject->createDocument('variables', new Document([
            'resourceType' => $resourceType,
            'resourceInternalId' => $resourceInternalId,
            'key' => $key,
            'value' => $value,
        ]));
    }

    /**
     * @return array{code: string, severity: string, platform: string, field: string, message: string, remediation: string, manualActionRequired: bool}
     */
    private function issue(string $severity, string $code, string $message, string $remediation): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'platform' => 'android',
            'field' => '',
            'message' => $message,
            'remediation' => $remediation,
            'manualActionRequired' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function ids(array $payload): array
    {
        return \array_map(fn (DownloadArtifact|UploadArtifact|UnarchiveArtifact|ReadArtifact $artifact): string => $artifact->id, $payload['artifacts']);
    }

    /**
     * @template T of object
     * @param array<string, mixed> $payload
     * @param class-string<T> $class
     * @return array<string, T> By artifact id
     */
    private function artifacts(array $payload, string $class): array
    {
        $artifacts = [];
        foreach ($payload['artifacts'] as $artifact) {
            if ($artifact instanceof $class) {
                $artifacts[$artifact->id] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * Restored by tearDown().
     */
    private function setEnvironment(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->environment)) {
            $this->environment[$name] = \getenv($name);
        }

        \putenv("{$name}={$value}");
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function runner(array $attributes = []): Document
    {
        return $this->dbForPlatform->createDocument('distributionRunners', new Document([
            'name' => 'mac-mini',
            'platform' => 'macos',
            'status' => 'online',
            'labels' => ['xcode-16.2'],
            'capacity' => 1,
            'seenAt' => DateTime::now(),
            ...$attributes,
        ]));
    }

    /**
     * A waiting iOS build of a target signed by an iOS identity with a profile for its bundle ID.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $build
     * @param array<string, mixed> $identity
     */
    private function iosBuild(array $target = [], array $build = [], array $identity = []): Document
    {
        $this->storedApp();
        $this->dbForProject->createDocument('distributionSigningIdentities', new Document([
            '$id' => 'identity1',
            'platform' => 'ios',
            'teamId' => 'ABCDE12345',
            'secret' => [
                'certificate' => 'cDEy',
                'password' => 'p12-password',
                'profiles' => ['io.appwrite.example' => 'cHJvZmlsZQ=='],
            ],
            ...$identity,
        ]));
        $this->storedTarget([
            'platform' => 'ios',
            'signingIdentityId' => 'identity1',
            'configuration' => ['xcode' => '16.2', 'scheme' => 'Runner'],
            ...$target,
        ]);

        return $this->storedBuild([
            'platform' => 'ios',
            'runner' => 'pool',
            'versionCode' => null,
            'buildNumber' => '7',
            ...$build,
        ]);
    }
}
