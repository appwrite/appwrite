<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Signing;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Artifact\DownloadArtifact;
use OpenRuntimes\Orchestrator\Model\Artifact\ReadArtifact;
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
use Tests\Unit\Distribution\Fakes\RemoteBuilds;
use Tests\Unit\Distribution\Fakes\SilentBroker;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
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

    protected function setUp(): void
    {
        foreach (self::SECRETS as $name => $value) {
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
        yield 'build uses the build key' => [Builds::STAGE_BUILD, 'build-callback-secret', 'signing-callback-secret', 'build-callback-secret'];
        yield 'build falls back to the jobs secret' => [Builds::STAGE_BUILD, '', 'signing-callback-secret', 'jobs-api-secret'];
        yield 'sign uses the signing key' => [Builds::STAGE_SIGN, 'build-callback-secret', 'signing-callback-secret', 'signing-callback-secret'];
        yield 'inspect uses the signing key' => [Builds::STAGE_INSPECT, 'build-callback-secret', 'signing-callback-secret', 'signing-callback-secret'];
        yield 'sign never falls back to the build key' => [Builds::STAGE_SIGN, 'build-callback-secret', '', 'jobs-api-secret'];
        yield 'inspect never falls back to the build key' => [Builds::STAGE_INSPECT, 'build-callback-secret', '', 'jobs-api-secret'];
        yield 'sign falls back to the jobs secret' => [Builds::STAGE_SIGN, '', '', 'jobs-api-secret'];
    }

    #[DataProvider('secrets')]
    public function testSecretIsThePlaneCallbackKey(string $stage, string $build, string $signing, string $expected): void
    {
        \putenv("_APP_DISTRIBUTION_CALLBACK_SECRET={$build}");
        \putenv("_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET={$signing}");

        $this->assertSame($expected, Builds::secret($stage));
    }

    public function testSigningJobNeverSignsWithTheBuildKey(): void
    {
        \putenv('_APP_DISTRIBUTION_SIGNING_CALLBACK_SECRET=');

        $payload = $this->builds(LocalBuilds::class)->inspectionPayload($this->project, $this->artifact('aab'));

        $callback = $payload['callback'];
        $this->assertInstanceOf(Callback::class, $callback);
        $this->assertSame('jobs-api-secret', $callback->key);
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
            ['apiHostname' => 'appwrite'],
            new DistributionBuildPublisher(new SilentBroker(), new Queue('v1-distribution-builds')),
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
}
