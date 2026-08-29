<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use Nyholm\Psr7\Response;
use OpenRuntimes\Orchestrator\Exception\ApiException as OrchestratorApiException;
use OpenRuntimes\Orchestrator\Exception\ClientException as OrchestratorClientException;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Client\Exception\NetworkException;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Query\Method;

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

    public function testSubmissionRecoversWhenTransportClosesAfterJobCreation(): void
    {
        $requests = [];
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$requests): Response {
                $requests[] = $request;
                if ($request->getMethod() === 'POST') {
                    throw new NetworkException($request, 'Connection closed after the job was accepted.');
                }

                return new Response(200, body: \json_encode([
                    'id' => 'project1-deployment1-build',
                    'status' => 'accepted',
                ], JSON_THROW_ON_ERROR));
            });

        $updates = 0;
        [$deployments, $resource, $deployment] = $this->fixture(
            $client,
            1,
            function (string $collection, Document $document, array $queries) use (&$updates): int {
                $updates++;
                $this->assertSame('deployments', $collection);
                $this->assertSame('waiting', $document->getAttribute('status'));

                return 1;
            },
        );

        $submitted = $deployments->createFromUpload($resource, $deployment);

        $this->assertSame('waiting', $submitted->getAttribute('status'));
        $this->assertSame(1, $updates);
        $this->assertSame('POST', $requests[0]->getMethod());
        $this->assertSame('GET', $requests[1]->getMethod());
        $this->assertSame('/v1/jobs/project1-deployment1-build', $requests[1]->getUri()->getPath());
    }

    public function testSubmissionPreservesCanceledStateWhenRecoveryFindsNoJob(): void
    {
        $status = 'uploading';
        $requests = 0;
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$requests, &$status): Response {
                $requests++;
                if ($request->getMethod() === 'POST') {
                    throw new NetworkException($request, 'Connection closed before the response.');
                }

                $status = 'canceled';

                return new Response(404, body: '{"error":"Job not found."}');
            });

        [$deployments, $resource, $deployment] = $this->fixture(
            $client,
            2,
            function (string $collection, Document $document, array $queries) use (&$status): int {
                $this->assertSame('deployments', $collection);

                if ($document->getAttribute('status') === 'waiting') {
                    $status = 'waiting';

                    return 1;
                }

                $this->assertSame('canceled', $status);
                $this->assertSame('failed', $document->getAttribute('status'));
                $this->assertTrue($this->hasCancelGuard($queries));

                return 0;
            },
        );

        try {
            $deployments->createFromUpload($resource, $deployment);
            $this->fail('Expected the lost submission response to remain an error when no job exists.');
        } catch (OrchestratorClientException $error) {
            $this->assertInstanceOf(NetworkException::class, $error->getPrevious());
        }

        $this->assertSame(2, $requests);
        $this->assertSame('canceled', $status);
    }

    public function testSubmissionDoesNotRecoverExplicitApiErrors(): void
    {
        $requests = 0;
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$requests): Response {
                $requests++;

                return new Response(401, body: '{"error":"Invalid jobs secret."}');
            });

        $status = 'uploading';
        [$deployments, $resource, $deployment] = $this->fixture(
            $client,
            2,
            function (string $collection, Document $document, array $queries) use (&$status): int {
                $status = $document->getAttribute('status');

                return 1;
            },
        );

        try {
            $deployments->createFromUpload($resource, $deployment);
            $this->fail('Expected an explicit jobs API error.');
        } catch (OrchestratorApiException $error) {
            $this->assertSame(401, $error->statusCode);
        }

        $this->assertSame(1, $requests);
        $this->assertSame('failed', $status);
    }

    /**
     * @param callable(string, Document, array<Query>): int $update
     * @return array{Deployments, Document, Document}
     */
    private function fixture(ClientInterface $client, int $updates, callable $update): array
    {
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        $runtime = \array_key_first(Config::getParam('runtimes-v2'));
        $project = new Document([
            '$id' => 'project1',
            'region' => 'default',
        ]);
        $resource = new Document([
            '$id' => 'function1',
            '$collection' => 'functions',
            'runtime' => $runtime,
        ]);
        $deployment = new Document([
            '$id' => 'deployment1',
            '$sequence' => 1,
            'buildCommands' => 'npm install',
        ]);
        $waiting = new Document([
            '$id' => 'deployment1',
            '$sequence' => 1,
            'status' => 'waiting',
        ]);

        $database = $this->createMock(Database::class);
        $database->expects($this->once())
            ->method('updateDocument')
            ->willReturn($deployment);
        $database->expects($this->exactly($updates))
            ->method('updateDocuments')
            ->willReturnCallback($update);
        $database->expects($this->once())
            ->method('getDocument')
            ->willReturn($waiting);

        return [
            new Deployments(new Jobs($client), $database, $project, ['apiHostname' => 'localhost']),
            $resource,
            $deployment,
        ];
    }

    /** @param array<Query> $queries */
    private function hasCancelGuard(array $queries): bool
    {
        foreach ($queries as $query) {
            if (
                $query->getMethod() === Method::NotEqual
                && $query->getAttribute() === 'status'
                && $query->getValues() === ['canceled']
            ) {
                return true;
            }
        }

        return false;
    }
}

final readonly class ExposedDeployments extends Deployments
{
    public static function submitPayload(Document $project, Document $resource, Document $deployment, array $platform): array
    {
        return static::payload($project, $resource, $deployment, $platform);
    }
}
