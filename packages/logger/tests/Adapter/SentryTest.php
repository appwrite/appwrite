<?php

declare(strict_types=1);

namespace Utopia\Logger\Tests\Adapter;

use Utopia\Client\Exception\ConnectionException;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class SentryTest extends AdapterTestCase
{
    public function testPushPostsTheEventToTheStoreEndpoint(): void
    {
        $this->client->respond(200);
        $log = $this->log();
        $log->addExtra('detailedTrace', [
            ['file' => 'Http.php', 'line' => 10, 'function' => 'run'],
            ['file' => 'Action.php', 'line' => 20, 'function' => 'action'],
        ]);

        new Sentry('42', 'public-key', 'https://sentry.example.com', client: $this->client)->push($log);

        $this->assertCount(1, $this->client->requests);
        $request = $this->client->last();
        $this->assertSame(Method::POST, $request->getMethod());
        $this->assertSame('https://sentry.example.com/api/42/store/', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('Sentry sentry_version=7, sentry_key=public-key, sentry_client=utopia-logger/0.1.0', $request->getHeaderLine('X-Sentry-Auth'));
        $this->assertSame([
            'timestamp' => 1700000000.25,
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'api',
            'transaction' => 'controller.database.deleteDocument',
            'server_name' => 'digitalocean-us-001',
            'release' => '0.11.5',
            'environment' => 'production',
            'message' => ['message' => 'Document efgh5678 not found'],
            'exception' => ['values' => [[
                'type' => 'Document efgh5678 not found',
                'stacktrace' => ['frames' => [
                    ['filename' => 'Action.php', 'lineno' => 20, 'function' => 'action'],
                    ['filename' => 'Http.php', 'lineno' => 10, 'function' => 'run'],
                ]],
            ]]],
            'tags' => ['sdk' => 'Flutter'],
            'extra' => [
                'file' => '/server/src/server.js',
                'line' => '15',
                'detailedTrace' => [
                    ['file' => 'Http.php', 'line' => 10, 'function' => 'run'],
                    ['file' => 'Action.php', 'line' => 20, 'function' => 'action'],
                ],
            ],
            'breadcrumbs' => [[
                'type' => 'default',
                'level' => 'debug',
                'category' => 'http',
                'message' => 'DELETE /v1/documents/efgh5678',
                'timestamp' => 1699999999.5,
            ]],
            'user' => ['id' => 'efgh5678', 'email' => 'user@example.com', 'username' => 'name'],
        ], $this->json($request));
    }

    public function testPushDefaultsToSentryIo(): void
    {
        $this->client->respond(200);

        new Sentry('42', 'public-key', client: $this->client)->push($this->log());

        $this->assertSame('https://sentry.io/api/42/store/', (string) $this->client->last()->getUri());
    }

    public function testPushLogsTheResponseOfARejectedEvent(): void
    {
        $this->captureErrors();
        $this->client->respond(401, 'Invalid api key');

        new Sentry('42', 'public-key', client: $this->client)->push($this->log());

        $this->assertStringContainsString('Sentry push failed with status code 401: Invalid api key', $this->errors());
    }

    public function testPushReturns500WhenTheRequestFails(): void
    {
        $this->captureErrors();
        $this->client->fail(new ConnectionException(new RequestFactory()->createRequest(Method::POST, 'https://sentry.io/api/42/store/'), 'Connection refused'));

        $status = new Sentry('42', 'public-key', client: $this->client)->push($this->log());

        $this->assertSame(500, $status);
        $this->assertStringContainsString('Sentry push failed with fetch error: Connection refused', $this->errors());
    }

    public function testPushSendsNothingWhenTheEventCannotBeEncoded(): void
    {
        $this->captureErrors();
        $log = $this->log();
        $log->addExtra('payload', "\xB1\x31");

        $status = new Sentry('42', 'public-key', client: $this->client)->push($log);

        $this->assertSame(500, $status);
        $this->assertSame([], $this->client->requests);
        $this->assertStringContainsString('Sentry push failed with fetch error: Failed to encode data to JSON: Malformed UTF-8', $this->errors());
    }
}
