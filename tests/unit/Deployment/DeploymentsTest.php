<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\VCS\Adapter\Git\GitHub;

final class DeploymentsTest extends TestCase
{
    public static function sources(): \Iterator
    {
        yield 'archive subdirectory' => [true, 'functions/api'];
        yield 'archive root' => [true, ''];
        yield 'clone subdirectory' => [false, 'functions/api'];
        yield 'clone root' => [false, ''];
    }

    #[DataProvider('sources')]
    public function testCreateVcsDeploymentPersistsSourceRoot(bool $archives, string $root): void
    {
        $previousKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        try {
            $database = new VcsDeploymentDatabase();
            $client = new VcsBuildClient();
            $deployments = new Deployments(new Jobs($client), $database, new Document(['$id' => 'project', 'region' => 'default']), ['apiHostname' => 'localhost']);

            $deployment = $deployments->createFromVcs(
                new Document([
                    '$id' => 'function',
                    '$collection' => 'functions',
                    'runtime' => \array_key_first(Config::getParam('runtimes-v2')),
                    'providerRootDirectory' => 'changed/function/setting',
                ]),
                new Document(['$id' => 'deployment', 'buildCommands' => 'npm ci']),
                900,
                new VcsRepository($archives),
                'owner',
                'repository',
                'commit',
                $root,
            );

            $this->assertSame('waiting', $deployment->getAttribute('status'));
            $this->assertSame($root, $database->getDocument('deployments', 'deployment')->getAttribute('providerRootDirectory'));
            $this->assertSame($root, $client->payload['environment']['APPWRITE_VCS_ROOT_DIRECTORY']);
            $artifacts = \array_column($client->payload['artifacts'], null, 'id');
            $this->assertSame($root, $artifacts[$archives ? 'extract' : 'source']['subdir'] ?? '');
        } finally {
            \putenv($previousKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousKey);
        }
    }

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

    private function buildPayload(array $vars): array
    {
        // Presigned-URL and ephemeral-key signing both run before the
        // variables are assembled, and refuse an empty key.
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        $runtimeKey = \array_key_first(Config::getParam('runtimes-v2'));

        return ExposedDeployments::submitPayload(
            new Document(['$id' => 'project1', 'region' => 'default']),
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
}

final readonly class ExposedDeployments extends Deployments
{
    public static function submitPayload(Document $project, Document $resource, Document $deployment, array $platform): array
    {
        return static::payload($project, $resource, $deployment, $platform, 137);
    }
}

final class VcsDeploymentDatabase extends Database
{
    private Document $deployment;

    public function __construct()
    {
    }

    public function createDocument(string $collection, Document $document): Document
    {
        $this->deployment = clone $document;
        $this->deployment->setAttribute('$sequence', '1');

        return clone $this->deployment;
    }

    public function updateDocuments(string $collection, Document $updates, array $queries = [], int $batchSize = self::INSERT_BATCH_SIZE, ?callable $onNext = null, ?callable $onError = null): int
    {
        $this->deployment->setAttributes($updates->getArrayCopy());

        return 1;
    }

    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        return clone $this->deployment;
    }
}

final class VcsBuildClient implements ClientInterface
{
    public array $payload = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->payload = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return new Response(202, body: new Stream('{"id":"build","status":"accepted"}'));
    }
}

final class VcsRepository extends GitHub
{
    public function __construct(private bool $archives)
    {
    }

    public function supportsRepositoryArchives(): bool
    {
        return $this->archives;
    }

    public function getRepositoryPresignedUrl(string $owner, string $repositoryName, string $ref = '', string $format = 'tarball'): string
    {
        return 'https://vcs.example/source.tar.gz';
    }

    public function getRepositoryCloneUrl(string $owner, string $repositoryName): string
    {
        return 'https://vcs.example/source.git';
    }
}
