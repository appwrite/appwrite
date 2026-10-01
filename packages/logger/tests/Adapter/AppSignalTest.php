<?php

declare(strict_types=1);

namespace Utopia\Logger\Tests\Adapter;

use Utopia\Client\Exception\ConnectionException;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class AppSignalTest extends AdapterTestCase
{
    public function testPushPostsTheErrorWithTheKeyInTheQuery(): void
    {
        $this->client->respond(204);

        new AppSignal('push-key', client: $this->client)->push($this->log());

        $this->assertCount(1, $this->client->requests);
        $request = $this->client->last();
        $this->assertSame(Method::POST, $request->getMethod());
        $this->assertSame('https://appsignal-endpoint.net/collect?api_key=push-key&version=1.3.19', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'timestamp' => 1700000000,
            'namespace' => 'api',
            'error' => [
                'name' => 'Document efgh5678 not found',
                'message' => 'Document efgh5678 not found',
                'backtrace' => [],
            ],
            'environment' => [
                'environment' => 'production',
                'server' => 'digitalocean-us-001',
                'version' => '0.11.5',
            ],
            'revision' => '0.11.5',
            'action' => 'controller.database.deleteDocument',
            'params' => ['file' => "'/server/src/server.js'", 'line' => "'15'"],
            'tags' => [
                'sdk' => 'utopia-logger/0.1.0',
                'type' => 'error',
                'userId' => 'efgh5678',
                'userName' => 'name',
                'userEmail' => 'user@example.com',
            ],
            'breadcrumbs' => [[
                'timestamp' => 1699999999,
                'category' => 'http',
                'action' => 'DELETE /v1/documents/efgh5678',
                'metadata' => ['type' => 'debug'],
            ]],
        ], $this->json($request));
    }

    public function testPushLogsTheResponseOfARejectedError(): void
    {
        $this->captureErrors();
        $this->client->respond(401);

        new AppSignal('push-key', client: $this->client)->push($this->log());

        $this->assertStringContainsString('AppSignal push failed with status code 401: ', $this->errors());
    }

    public function testPushReturns500WhenTheRequestFails(): void
    {
        $this->captureErrors();
        $this->client->fail(new ConnectionException(new RequestFactory()->createRequest(Method::POST, 'https://appsignal-endpoint.net/collect'), 'Connection refused'));

        $status = new AppSignal('push-key', client: $this->client)->push($this->log());

        $this->assertSame(500, $status);
        $this->assertStringContainsString('AppSignal push failed with fetch error: Connection refused', $this->errors());
    }
}
