<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Exception\ApiException as OrchestratorApiException;
use OpenRuntimes\Orchestrator\Exception\ClientException as OrchestratorClientException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\Volume;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Client\Exception\NetworkException;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

final class DeploymentsTest extends TestCase
{
    private const string COLLECTION = 'deployments';
    private const string DEPLOYMENT = 'deployment1';

    public function testSiteCommandIncludesFrameworkAndDeploymentCommands(): void
    {
        Config::setParam('frameworks', [
            'astro' => [
                'envCommand' => 'cp .env.example .env',
                'bundleCommand' => 'npm run bundle',
            ],
        ]);

        $resource = new Document([
            '$collection' => 'sites',
            'framework' => 'astro',
        ]);
        $deployment = new Document([
            'buildCommands' => 'npm run build',
        ]);

        $this->assertSame(
            'cp .env.example .env && npm run build && npm run bundle',
            Deployments::command($resource, $deployment)
        );
    }

    public function testFunctionCommandIsDeploymentBuildCommands(): void
    {
        $resource = new Document(['$collection' => 'functions']);
        $deployment = new Document(['buildCommands' => 'npm install']);

        $this->assertSame('npm install', Deployments::command($resource, $deployment));
    }

    public function testEmptyStartCommandUsesDefault(): void
    {
        $this->assertSame(
            'bash helpers/server.sh',
            Deployments::startCommand(new Document(['startCommand' => '']), 'bash helpers/server.sh')
        );
    }

    public function testPersistedDefaultStartCommandDoesNotCdIntoSource(): void
    {
        $default = 'bash helpers/server.sh';

        $this->assertSame(
            $default,
            Deployments::startCommand(new Document(['startCommand' => $default]), $default)
        );
    }

    public function testPersistedFrameworkSsrDefaultStartCommandDoesNotCdIntoSource(): void
    {
        $default = 'bash helpers/angular/server.sh';

        $this->assertSame(
            $default,
            Deployments::startCommand(new Document(['startCommand' => $default]), $default)
        );
    }

    public function testCustomStartCommandCdsIntoSourceAndEscapes(): void
    {
        $this->assertSame(
            'cd /usr/local/server/src/function/ && npm start --prefix=\"\$HOME\"',
            Deployments::startCommand(
                new Document(['startCommand' => 'npm start --prefix="$HOME"']),
                'bash helpers/server.sh'
            )
        );
    }

    public function testCustomStartCommandEscapesBackticksAndQuotes(): void
    {
        $this->assertSame(
            'cd /usr/local/server/src/function/ && echo \"hi\" && echo \`id\`',
            Deployments::startCommand(
                new Document(['startCommand' => 'echo "hi" && echo `id`']),
                'bash helpers/server.sh'
            )
        );
    }

    public function testScopesMergeGrantsForResourceType(): void
    {
        Config::setParam('computeScopes', [
            'functions' => ['health.read'],
            'sites' => ['proxy.invalidations.write'],
        ]);

        $function = new Document([
            '$collection' => 'functions',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(['users.read', 'health.read'], Deployments::scopes($function));

        // Deduplicates when the resource already holds a granted scope
        $site = new Document([
            '$collection' => 'sites',
            'scopes' => ['users.read', 'proxy.invalidations.write'],
        ]);

        $this->assertSame(['users.read', 'proxy.invalidations.write'], Deployments::scopes($site));
    }

    public function testScopesEmptyGrantsKeepResourceScopes(): void
    {
        Config::setParam('computeScopes', ['functions' => [], 'sites' => []]);

        $site = new Document([
            '$collection' => 'sites',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(['users.read'], Deployments::scopes($site));
    }

    private function buildPayload(array $vars, string $projectId = 'project1'): array
    {
        // Presigned-URL and ephemeral-key signing both run before the
        // variables are assembled, and refuse an empty key.
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        $runtimeKey = \array_key_first(Config::getParam('runtimes-v2'));

        return ExposedDeployments::submitPayload(
            new Document(['$id' => $projectId, 'region' => 'default']),
            new Document([
                '$id' => 'function1',
                '$collection' => 'functions',
                'runtime' => $runtimeKey,
                'vars' => \array_map(
                    fn (string $key, string $value) => new Document(['key' => $key, 'value' => $value]),
                    \array_keys($vars),
                    \array_values($vars),
                ),
            ]),
            new Document(['$id' => 'deployment1', 'buildCommands' => 'npm install']),
            ['apiHostname' => 'localhost'],
        );
    }

    public function testPayloadRefusesVariableKeyTheClusterWouldRefuse(): void
    {
        try {
            $this->buildPayload(["A\x00C\x00M\x00E_KEY" => 'secret']);
            $this->fail('Expected the invalid variable key to be refused before job submission');
        } catch (Exception $error) {
            $this->assertSame(Exception::VARIABLE_INVALID_KEY, $error->getType());
            $this->assertStringContainsString(\json_encode("A\x00C\x00M\x00E_KEY"), $error->getMessage());
            $this->assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function testPayloadKeepsLegacyKeysTheClusterAccepts(): void
    {
        // MY-VAR predates the strict endpoint rule but deploys fine; the
        // build-layer guard must not take working deployments down with it.
        $payload = $this->buildPayload(['MY-VAR' => 'v1', 'MY_VAR' => 'v2']);

        $this->assertSame('v1', $payload['environment']['MY-VAR']);
        $this->assertSame('v2', $payload['environment']['MY_VAR']);
    }

    public function testSubmissionRecoversWhenTransportClosesAfterJobCreation(): void
    {
        $database = $this->database();
        $service = new JobsService(Submission::LostAfterAccepting);

        $submitted = $this->deployments($service, $database)->createFromUpload($this->resource(), $this->stored($database), 900);

        $this->assertSame('waiting', $submitted->getAttribute('status'));
        $this->assertSame('waiting', $this->stored($database)->getAttribute('status'));
        $this->assertCount(1, $service->jobs, 'The deployment must be left waiting on the job the service accepted.');
    }

    public function testSubmissionPreservesCanceledStateWhenRecoveryFindsNoJob(): void
    {
        $database = $this->database();
        $service = new JobsService(
            Submission::LostBeforeAccepting,
            fn () => $database->updateDocument(self::COLLECTION, self::DEPLOYMENT, new Document(['status' => 'canceled'])),
        );

        try {
            $this->deployments($service, $database)->createFromUpload($this->resource(), $this->stored($database), 900);
            $this->fail('Expected the lost submission response to remain an error when no job exists.');
        } catch (OrchestratorClientException $error) {
            $this->assertInstanceOf(NetworkException::class, $error->getPrevious());
        }

        $this->assertSame('canceled', $this->stored($database)->getAttribute('status'));
        $this->assertSame([], $service->jobs);
    }

    public function testSubmissionLeavesDeploymentCanceledBeforeQueueing(): void
    {
        $database = $this->database();
        $stale = $this->stored($database);
        $database->updateDocument(self::COLLECTION, self::DEPLOYMENT, new Document(['status' => 'canceled']));
        $service = new JobsService(Submission::LostAfterAccepting);

        $submitted = $this->deployments($service, $database)->createFromUpload($this->resource(), $stale, 900);

        $this->assertSame('canceled', $submitted->getAttribute('status'));
        $this->assertSame('canceled', $this->stored($database)->getAttribute('status'));
        $this->assertSame([], $service->jobs);
    }

    public function testSubmissionDoesNotRecoverExplicitApiErrors(): void
    {
        $database = $this->database();
        $service = new JobsService(Submission::FailedAfterAccepting);

        try {
            $this->deployments($service, $database)->createFromUpload($this->resource(), $this->stored($database), 900);
            $this->fail('Expected an explicit jobs API error, even though the service holds the job.');
        } catch (OrchestratorApiException $error) {
            $this->assertSame(500, $error->statusCode);
        }

        $this->assertSame('failed', $this->stored($database)->getAttribute('status'));
    }

    private function deployments(JobsService $service, Database $database): Deployments
    {
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        return new Deployments(
            new Jobs($service),
            $database,
            new Document(['$id' => 'project1', 'region' => 'default']),
            ['apiHostname' => 'localhost'],
        );
    }

    private function resource(): Document
    {
        return new Document([
            '$id' => 'function1',
            '$collection' => 'functions',
            'runtime' => \array_key_first(Config::getParam('runtimes-v2')),
        ]);
    }

    private function stored(Database $database): Document
    {
        return $database->getDocument(self::COLLECTION, self::DEPLOYMENT);
    }

    private function database(): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $database = (new Database(new Memory(), new Cache(new None())))
            ->setDatabase('appwrite')
            ->setNamespace('deployments')
            ->setAuthorization($authorization);

        $authorization->skip(function () use ($database): void {
            $database->create();
            $database->createCollection(new Collection(
                id: self::COLLECTION,
                attributes: [
                    Attribute::string(key: 'resourceId'),
                    Attribute::string(key: 'resourceInternalId'),
                    Attribute::string(key: 'resourceType'),
                    Attribute::string(key: 'buildCommands', size: 1024),
                    Attribute::string(key: 'status'),
                    Attribute::string(key: 'buildPath', size: 1024),
                    Attribute::string(key: 'buildLogs', size: 1024),
                    Attribute::datetime(key: 'buildEndedAt', filters: ['datetime']),
                    Attribute::boolean(key: 'activate', default: false),
                ],
                permissions: [
                    Permission::create(Role::any()),
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                documentSecurity: false,
            ));
            $database->createDocument(self::COLLECTION, new Document([
                '$id' => self::DEPLOYMENT,
                'resourceId' => 'function1',
                'resourceType' => 'functions',
                'buildCommands' => 'npm install',
                'status' => 'uploading',
            ]));
        });

        return $database;
    }

    public function testLocalBuildMountsOnlyTheProjectDirectory(): void
    {
        $payload = $this->withLocalBuilds(fn () => $this->buildPayload([]));

        $this->assertCount(1, $payload['volumes']);
        $this->assertInstanceOf(Volume::class, $payload['volumes'][0]);

        $volume = $payload['volumes'][0];
        $projectRoot = APP_STORAGE_BUILDS . '/app-project1';

        $this->assertSame('appwrite-builds', $volume->source);
        $this->assertSame($projectRoot, $volume->path);
        $this->assertSame('app-project1', $volume->subPath);
        $this->assertNotSame(APP_STORAGE_BUILDS, $volume->path);

        $serialized = $volume->toArray();
        $this->assertSame('app-project1', $serialized['subPath']);
        $this->assertSame($projectRoot, $serialized['path']);

        $this->assertSame(
            Deployments::outputDirectory('project1', 'deployment1'),
            $payload['environment']['OPEN_RUNTIMES_BUILD_OUTPUT_DIR']
        );
        $this->assertSame(
            Deployments::outputDirectory('project1', 'deployment1') . '/' . Deployments::artifact(),
            Deployments::buildPath('project1', 'deployment1')
        );

        $runtimeKey = \array_key_first(Config::getParam('runtimes-v2'));
        $image = Config::getParam('runtimes-v2')[$runtimeKey]['image'] ?? '';
        $this->assertSame(
            Deployments::cachePath('project1', Deployments::cacheKey('project1', 'function1', $image)),
            $payload['environment']['OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT']
        );
        $this->assertStringStartsWith($projectRoot . '/', $payload['environment']['OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT']);
    }

    public function testLocalBuildMountCannotReachSiblingProject(): void
    {
        [$first, $second] = $this->withLocalBuilds(fn () => [
            $this->buildPayload([], 'project1'),
            $this->buildPayload([], 'project2'),
        ]);

        $firstVolume = $first['volumes'][0];
        $secondVolume = $second['volumes'][0];
        $sibling = APP_STORAGE_BUILDS . '/app-project2';

        // The previous Local strategy mounted APP_STORAGE_BUILDS, so every
        // sibling app-{projectId} path was inside the worker. Isolation is
        // the mount itself: a sibling must not sit under the attached path.
        $this->assertFalse(\str_starts_with($sibling, \rtrim($firstVolume->path, '/') . '/'));
        $this->assertSame('app-project1', $firstVolume->subPath);
        $this->assertSame('app-project2', $secondVolume->subPath);
        $this->assertNotSame($firstVolume->path, $secondVolume->path);
        $this->assertStringNotContainsString('project2', (string) $firstVolume->path);
        $this->assertStringNotContainsString('project2', $firstVolume->subPath);
        $this->assertSame(
            Deployments::outputDirectory('project2', 'deployment1'),
            $second['environment']['OPEN_RUNTIMES_BUILD_OUTPUT_DIR']
        );
        $this->assertStringStartsWith($secondVolume->path . '/', $second['environment']['OPEN_RUNTIMES_BUILD_OUTPUT_DIR']);
        $this->assertSame(
            Deployments::buildPath('project2', 'deployment1'),
            Deployments::outputDirectory('project2', 'deployment1') . '/' . Deployments::artifact()
        );
    }

    /**
     * @template T
     * @param callable(): T $test
     * @return T
     */
    private function withLocalBuilds(callable $test): mixed
    {
        $device = \getenv('_APP_STORAGE_DEVICE');
        $connection = \getenv('_APP_CONNECTIONS_STORAGE');
        $volume = \getenv('_APP_BUILDS_VOLUME');

        \putenv('_APP_STORAGE_DEVICE=Local');
        \putenv('_APP_CONNECTIONS_STORAGE=');
        \putenv('_APP_BUILDS_VOLUME=appwrite-builds');

        try {
            return $test();
        } finally {
            $device === false ? \putenv('_APP_STORAGE_DEVICE') : \putenv('_APP_STORAGE_DEVICE=' . $device);
            $connection === false ? \putenv('_APP_CONNECTIONS_STORAGE') : \putenv('_APP_CONNECTIONS_STORAGE=' . $connection);
            $volume === false ? \putenv('_APP_BUILDS_VOLUME') : \putenv('_APP_BUILDS_VOLUME=' . $volume);
        }
    }
}

final readonly class ExposedDeployments extends Deployments
{
    public static function submitPayload(Document $project, Document $resource, Document $deployment, array $platform): array
    {
        return static::payload($project, $resource, $deployment, $platform, 137);
    }
}
