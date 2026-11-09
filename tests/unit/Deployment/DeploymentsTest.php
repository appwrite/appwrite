<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Model\Volume;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Storage\Device\Local;

final class DeploymentsTest extends TestCase
{
    public function testLockIsScopedToTheProject(): void
    {
        $this->assertNotSame(Deployments::lock('1', 'deployment1'), Deployments::lock('2', 'deployment1'));
        $this->assertSame(Deployments::lock('1', 'deployment1'), Deployments::lock('1', 'deployment1'));
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

    public function testLocalBuildMountsOnlyItsOutputAndCache(): void
    {
        $payload = $this->withLocalBuilds(fn () => $this->buildPayload([]));

        $this->assertCount(2, $payload['volumes']);
        [$output, $cache] = $payload['volumes'];
        $this->assertInstanceOf(Volume::class, $output);
        $this->assertInstanceOf(Volume::class, $cache);

        $projectRoot = APP_STORAGE_BUILDS . '/app-project1';

        $this->assertSame('appwrite-builds', $output->source);
        $this->assertSame(Deployments::outputDirectory('project1', 'deployment1'), $output->path);
        $this->assertSame('app-project1/deployment1', $output->subPath);
        $this->assertSame('app-project1/deployment1', $output->toArray()['subPath']);

        $this->assertSame('appwrite-builds', $cache->source);
        $this->assertSame($projectRoot . '/cache', $cache->path);
        $this->assertSame('app-project1/cache', $cache->subPath);

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
        $this->assertStringStartsWith($cache->path . '/', $payload['environment']['OPEN_RUNTIMES_BUILD_CACHE_ARTIFACT']);
    }

    public function testLocalBuildMountCannotReachSiblingDeployment(): void
    {
        [$first, $second] = $this->withLocalBuilds(fn () => [
            $this->buildPayload([], 'project1'),
            $this->buildPayload([], 'project2'),
        ]);

        $siblings = [
            Deployments::buildPath('project1', 'deployment2'),
            Deployments::buildPath('project2', 'deployment1'),
        ];

        foreach ($first['volumes'] as $volume) {
            foreach ($siblings as $sibling) {
                $this->assertFalse(\str_starts_with($sibling, \rtrim($volume->path, '/') . '/'));
            }
            $this->assertStringStartsWith('app-project1/', $volume->subPath);
        }

        foreach ($second['volumes'] as $volume) {
            $this->assertStringStartsWith('app-project2/', $volume->subPath);
        }
        $this->assertStringStartsWith($second['volumes'][0]->path . '/', Deployments::buildPath('project2', 'deployment1'));
    }

    public function testArtifactMustBeRegularFileInsideDevice(): void
    {
        $base = \realpath(\sys_get_temp_dir()) . '/artifact-' . \bin2hex(\random_bytes(6));
        $root = $base . '/app-project1';
        \mkdir($root . '/deployment1', 0o777, true);
        \mkdir($base . '/outside');
        \file_put_contents($root . '/deployment1/code.tar.gz', 'artifact');
        \file_put_contents($base . '/outside/secret', 'secret');
        \symlink($base . '/outside/secret', $root . '/deployment1/link.tar.gz');
        \symlink($base . '/outside', $root . '/linked');
        \file_put_contents($base . '/sibling.tar.gz', 'sibling');
        $device = new Local($root);

        try {
            $this->assertTrue(Deployments::isArtifact($device, $root . '/deployment1/code.tar.gz'));

            $this->assertFalse(Deployments::isArtifact($device, ''));
            $this->assertFalse(Deployments::isArtifact($device, $root . '/deployment1/missing.tar.gz'));
            $this->assertFalse(Deployments::isArtifact($device, $root . '/deployment1'));
            $this->assertFalse(Deployments::isArtifact($device, $root . '/deployment1/link.tar.gz'));
            $this->assertFalse(Deployments::isArtifact($device, $root . '/linked/secret'));
            $this->assertFalse(Deployments::isArtifact($device, $root . '/deployment1/../../outside/secret'));
            $this->assertFalse(Deployments::isArtifact($device, $base . '/sibling.tar.gz'));
            $this->assertFalse(Deployments::isArtifact($device, $base . '/outside/secret'));
        } finally {
            \unlink($root . '/deployment1/link.tar.gz');
            \unlink($root . '/linked');
            \unlink($root . '/deployment1/code.tar.gz');
            \unlink($base . '/outside/secret');
            \unlink($base . '/sibling.tar.gz');
            \rmdir($root . '/deployment1');
            \rmdir($root);
            \rmdir($base . '/outside');
            \rmdir($base);
        }
    }

    public function testOpenArtifactKeepsReadingTheCheckedFile(): void
    {
        $base = \realpath(\sys_get_temp_dir()) . '/open-artifact-' . \bin2hex(\random_bytes(6));
        $root = $base . '/app-project1';
        \mkdir($root . '/deployment1', 0o777, true);
        \file_put_contents($root . '/deployment1/code.tar.gz', 'artifact');
        \file_put_contents($base . '/secret', 'secret');
        \symlink($base . '/secret', $root . '/deployment1/link.tar.gz');
        $device = new Local($root);

        try {
            $this->assertNull(Deployments::openArtifact($device, $root . '/deployment1/link.tar.gz'));
            $this->assertNull(Deployments::openArtifact($device, $root . '/deployment1/missing.tar.gz'));
            $this->assertNull(Deployments::openArtifact($device, $base . '/secret'));

            $artifact = Deployments::openArtifact($device, $root . '/deployment1/code.tar.gz');
            $this->assertInstanceOf(StreamInterface::class, $artifact);
            $this->assertSame(8, $artifact->getSize());

            // The build swaps the checked file for a link once the download started
            \unlink($root . '/deployment1/code.tar.gz');
            \symlink($base . '/secret', $root . '/deployment1/code.tar.gz');

            $this->assertSame('artifact', $artifact->getContents());
            $artifact->close();
        } finally {
            \unlink($root . '/deployment1/code.tar.gz');
            \unlink($root . '/deployment1/link.tar.gz');
            \unlink($base . '/secret');
            \rmdir($root . '/deployment1');
            \rmdir($root);
            \rmdir($base);
        }
    }

    public function testDeleteBuildDirectoryReportsWhetherAnythingIsLeft(): void
    {
        $base = \realpath(\sys_get_temp_dir()) . '/build-directory-' . \bin2hex(\random_bytes(6));
        $root = $base . '/app-project1';
        \mkdir($root . '/deployment1/output', 0o777, true);
        \mkdir($base . '/outside');
        \file_put_contents($root . '/deployment1/code.tar.gz', 'artifact');
        \file_put_contents($root . '/deployment1/output/extra', 'extra');
        \file_put_contents($base . '/outside/kept', 'kept');
        \symlink($base . '/outside', $root . '/linked');
        $device = new Local($root);

        try {
            $this->assertTrue(Deployments::deleteBuildDirectory($device, 'deployment1'));
            $this->assertFileDoesNotExist($root . '/deployment1');

            // Nothing left counts as deleted, so a retried cleanup succeeds.
            $this->assertTrue(Deployments::deleteBuildDirectory($device, 'deployment1'));

            // A directory reached through a symbolic link is refused, not followed.
            $this->assertFalse(Deployments::deleteBuildDirectory($device, 'linked'));
            $this->assertSame('kept', \file_get_contents($base . '/outside/kept'));
        } finally {
            \unlink($root . '/linked');
            \unlink($base . '/outside/kept');
            \rmdir($base . '/outside');
            \rmdir($root);
            \rmdir($base);
        }
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

    public function testIdKeepsHyphenFreePairs(): void
    {
        $this->assertSame('6a1b2c3d-6e4f5a6b-build', Deployments::id('6a1b2c3d', '6e4f5a6b'));
    }

    public function testIdSeparatesHyphenatedPairs(): void
    {
        $this->assertNotSame(Deployments::id('my-app', 'deployment'), Deployments::id('my', 'app-deployment'));
    }
}

final readonly class ExposedDeployments extends Deployments
{
    public static function submitPayload(Document $project, Document $resource, Document $deployment, array $platform): array
    {
        return static::payload($project, $resource, $deployment, $platform, 137);
    }
}
