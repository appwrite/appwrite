<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
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

    public function testPayloadInternalEndpointIsHttpEvenWhenForceHttpsEnabled(): void
    {
        // Regression: with the documented self-hosted default
        // (_APP_OPTIONS_FORCE_HTTPS=enabled) and no _APP_JOBS_ENDPOINT override,
        // the source presigned URL and the CloudEvents callback URL must reach
        // Appwrite over plain HTTP. The jobs-service and its sidecars live on
        // the internal Docker network where nothing listens on port 443 — an
        // https://appwrite/... URL leaves deployments stuck in "waiting".
        $this->withEnv(
            ['_APP_OPTIONS_FORCE_HTTPS' => 'enabled', '_APP_JOBS_ENDPOINT' => ''],
            function (): void {
                $payload = $this->buildPayload([]);

                $this->assertInternalEndpoint($payload['callback']->url, 'localhost');
                $this->assertInternalEndpoint(
                    $this->findSourceArtifact($payload)->in,
                    'localhost'
                );
            }
        );
    }

    public function testPayloadRespectsExplicitJobsEndpointOverride(): void
    {
        // _APP_JOBS_ENDPOINT stays the supported escape hatch for non-default
        // internal topologies — its value must win regardless of the public
        // _APP_OPTIONS_FORCE_HTTPS flag.
        $this->withEnv(
            [
                '_APP_OPTIONS_FORCE_HTTPS' => 'enabled',
                '_APP_JOBS_ENDPOINT' => 'http://internal-appwrite:9000',
            ],
            function (): void {
                $payload = $this->buildPayload([]);

                $this->assertInternalEndpoint(
                    $payload['callback']->url,
                    'internal-appwrite:9000'
                );
                $this->assertInternalEndpoint(
                    $this->findSourceArtifact($payload)->in,
                    'internal-appwrite:9000'
                );
            }
        );
    }

    /**
     * Asserts the URL is reachable over HTTP on the given internal authority
     * (host[:port]). Treats the path, query, and any future route changes as
     * opaque — the regression is the scheme + authority, not the URL shape.
     */
    private function assertInternalEndpoint(string $url, string $expectedAuthority): void
    {
        $parts = \parse_url($url);
        $this->assertIsArray($parts, "Expected a valid URL, got: {$url}");
        $this->assertSame('http', $parts['scheme'] ?? null, "Expected http scheme in {$url}");

        $actualAuthority = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->assertSame($expectedAuthority, $actualAuthority, "Expected authority {$expectedAuthority} in {$url}");
    }

    /**
     * @param array<string, string> $overrides
     */
    private function withEnv(array $overrides, callable $then): void
    {
        $previous = [];
        foreach ($overrides as $key => $value) {
            $previous[$key] = \getenv($key);
            if ($value === '') {
                \putenv($key);
            } else {
                \putenv($key . '=' . $value);
            }
        }
        try {
            $then();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    \putenv($key);
                } else {
                    \putenv($key . '=' . $value);
                }
            }
        }
    }

    private function findSourceArtifact(array $payload): object
    {
        foreach ($payload['artifacts'] as $artifact) {
            if ($artifact->id === 'source') {
                return $artifact;
            }
        }
        $this->fail('No source artifact in payload');
    }
}

final readonly class ExposedDeployments extends Deployments
{
    public static function submitPayload(Document $project, Document $resource, Document $deployment, array $platform): array
    {
        return static::payload($project, $resource, $deployment, $platform);
    }
}
