<?php

declare(strict_types=1);

namespace Tests\Unit\Sandbox;

use Appwrite\Sandbox\Client;
use Appwrite\Sandbox\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ClientTest extends TestCase
{
    private ?RequestInterface $request = null;

    private function client(ResponseInterface $response): Client
    {
        $test = $this;
        return new Client(new class ($response, $test) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private ClientTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->setRequest($request);
                return $this->response;
            }
        });
    }

    public function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    public function testCreate(): void
    {
        $response = (new Response(201, body: new Stream('{"id":"p1-abc","status":"ready","url":"http://s-token.sandboxes.test"}')))
            ->withHeader('Content-Type', 'application/json');

        $status = $this->client($response)->create(
            id: 'p1-abc',
            image: 'python:3.12-slim',
            port: 4000,
            command: 'sleep infinity',
            cpu: 2.0,
            memory: 2048,
            environment: ['GREETING' => 'hello'],
            ports: [5173],
            timeoutSeconds: 0,
            idleTimeoutSeconds: 60,
        );

        $this->assertSame('ready', $status['status']);
        $this->assertSame('http://s-token.sandboxes.test', $status['url']);
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('/v1/sandbox', (string)$this->request->getUri());
        $this->assertEquals([
            'id' => 'p1-abc',
            'image' => 'python:3.12-slim',
            'port' => 4000,
            'cpu' => 2.0,
            'memory' => 2048,
            'timeoutSeconds' => 0,
            'idleTimeoutSeconds' => 60,
            'command' => 'sleep infinity',
            'environment' => ['GREETING' => 'hello'],
            'ports' => [5173],
        ], \json_decode((string)$this->request->getBody(), true));
    }

    public function testCreateOmitsUnsetOptionals(): void
    {
        $response = (new Response(201, body: new Stream('{"id":"p1-abc","status":"ready"}')))
            ->withHeader('Content-Type', 'application/json');

        $this->client($response)->create(id: 'p1-abc', image: 'python:3.12-slim');

        // The orchestrator rejects unknown fields and reads an empty one as a
        // value, so an unset option must not reach the wire at all.
        $body = \json_decode((string)$this->request->getBody(), true);
        $this->assertArrayNotHasKey('command', $body);
        $this->assertArrayNotHasKey('environment', $body);
        $this->assertArrayNotHasKey('ports', $body);
        // json_encode drops the fraction on a whole float, so 1.0 goes out as
        // 1 — which the orchestrator still reads as its float cpu field.
        $this->assertEqualsWithDelta(1.0, $body['cpu'], PHP_FLOAT_EPSILON);
        $this->assertSame(512, $body['memory']);
        $this->assertSame(300, $body['timeoutSeconds']);
        $this->assertSame(900, $body['idleTimeoutSeconds']);
    }

    public function testGet(): void
    {
        $response = (new Response(200, body: new Stream('{"id":"p1-abc","status":"ready"}')))
            ->withHeader('Content-Type', 'application/json');

        $status = $this->client($response)->get('p1-abc');

        $this->assertSame('p1-abc', $status['id']);
        $this->assertSame('GET', $this->request->getMethod());
        $this->assertSame('/v1/sandbox/p1-abc', (string)$this->request->getUri());
    }

    public function testList(): void
    {
        $response = (new Response(200, body: new Stream('{"sandboxes":[{"id":"p1-abc"},{"id":"p2-def"}]}')))
            ->withHeader('Content-Type', 'application/json');

        $sandboxes = $this->client($response)->list();

        $this->assertCount(2, $sandboxes);
        $this->assertSame('/v1/sandbox', (string)$this->request->getUri());
    }

    public function testDelete(): void
    {
        $this->client(new Response(204))->delete('p1-abc');

        $this->assertSame('DELETE', $this->request->getMethod());
        $this->assertSame('/v1/sandbox/p1-abc', (string)$this->request->getUri());
    }

    public function testError(): void
    {
        $response = (new Response(404, body: new Stream('{"error":"sandbox not found"}')))
            ->withHeader('Content-Type', 'application/json');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('sandbox not found');
        $this->expectExceptionCode(404);

        $this->client($response)->get('p1-missing');
    }

    public function testErrorWithoutBody(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Sandbox request failed with status 502');

        $this->client(new Response(502))->list();
    }
}
