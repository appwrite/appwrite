<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;

final class DeploymentsTest extends TestCase
{
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

    public static function rootDirectories(): \Iterator
    {
        yield 'repository root' => ['', ''];
        yield 'dot' => ['.', ''];
        yield 'dot slash' => ['./', ''];
        yield 'bare' => ['docs', 'docs'];
        yield 'trailing slash' => ['docs/', 'docs'];
        yield 'dot slash prefix' => ['./docs', 'docs'];
        yield 'dot slash prefix and trailing slash' => ['./docs/', 'docs'];
        yield 'leading slash' => ['/docs', 'docs'];
        yield 'nested' => ['docs/nested', 'docs/nested'];
        yield 'nested with prefix and trailing slash' => ['./docs/nested/', 'docs/nested'];
        yield 'shipped site template' => ['./astro/starter', 'astro/starter'];
        yield 'hidden directory' => ['.github', '.github'];
        yield 'dot-prefixed name' => ['..foo', '..foo'];
        yield 'nested hidden directory' => ['./.github/actions', '.github/actions'];
    }

    #[DataProvider('rootDirectories')]
    public function testRootDirectoryCanonicalizes(string $rootDirectory, string $expected): void
    {
        $this->assertSame($expected, Deployments::rootDirectory($rootDirectory));
    }

    #[DataProvider('escapingRootDirectories')]
    public function testRootDirectoryRefusesSegmentLeavingTheRepository(string $rootDirectory): void
    {
        try {
            Deployments::rootDirectory($rootDirectory);
            $this->fail('Expected the root directory to be refused before job submission');
        } catch (Exception $error) {
            $this->assertSame(Exception::DEPLOYMENT_INVALID_ROOT_DIRECTORY, $error->getType());
        }
    }

    public static function escapingRootDirectories(): \Iterator
    {
        yield 'parent' => ['..'];
        yield 'parent path' => ['../etc'];
        yield 'parent path with prefix' => ['./../etc'];
        // Dropping the segment would silently build 'docs/x'; resolving it,
        // 'x'. Neither is the directory the caller named.
        yield 'interior parent' => ['docs/../x'];
        yield 'escaping interior parent' => ['./docs/../../etc'];
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

    private function buildPayload(array $vars, ?array $source = null): array
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
            $source,
        );
    }

    private function artifact(array $payload, string $id): object
    {
        foreach ($payload['artifacts'] as $artifact) {
            if ($artifact->id === $id) {
                return $artifact;
            }
        }

        $this->fail("No '{$id}' artifact in the payload");
    }

    public function testPayloadUnarchivesFromCanonicalSubdirectory(): void
    {
        $payload = $this->buildPayload([], ['url' => 'https://example.com/x.tar.gz', 'subdir' => './docs/nested/']);

        $this->assertSame('docs/nested', $this->artifact($payload, 'extract')->subdir);
    }

    #[DataProvider('repositoryRoots')]
    public function testPayloadOmitsSubdirectoryForRepositoryRoot(string $rootDirectory): void
    {
        $payload = $this->buildPayload([], ['url' => 'https://example.com/x.tar.gz', 'subdir' => $rootDirectory]);

        $this->assertNull($this->artifact($payload, 'extract')->subdir);
    }

    public static function repositoryRoots(): \Iterator
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot slash' => ['./'];
    }

    public function testPayloadClonesFromCanonicalSubdirectory(): void
    {
        $payload = $this->buildPayload([], ['clone' => 'https://example.com/r.git', 'ref' => 'main', 'subdir' => './docs']);

        $this->assertSame('docs', $this->artifact($payload, 'source')->subdir);
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
    public static function submitPayload(Document $project, Document $resource, Document $deployment, array $platform, ?array $source = null): array
    {
        return static::payload($project, $resource, $deployment, $platform, $source);
    }
}
