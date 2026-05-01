<?php

namespace Tests\Unit\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Adapter\Webhook;
use Appwrite\Utopia\Messaging\Messages\Webhook as WebhookMessage;
use PHPUnit\Framework\TestCase;

/**
 * Test double that captures the curl request the adapter would issue and
 * returns a scripted response, so we exercise the real signing/header logic
 * without touching the network.
 */
class CapturingWebhook extends Webhook
{
    /**
     * @var array<int, array{method: string, url: string, headers: array<int, string>, body: string, timeout: int}>
     */
    public array $captured = [];

    /** @var array{statusCode: int, response: string|null, error: string|null} */
    public array $response = ['statusCode' => 200, 'response' => 'OK', 'error' => null];

    protected function dispatch(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $this->captured[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];
        return $this->response;
    }
}

class WebhookTest extends TestCase
{
    public function testPostsExpectedBodyShape(): void
    {
        $adapter = new CapturingWebhook();
        $payload = [
            'subject' => 'Hello',
            'body' => 'World',
            'recipient' => 'ops@example.test',
            'metadata' => ['foo' => 'bar'],
        ];

        $message = new WebhookMessage(
            urls: ['https://hooks.example.test/notify'],
            payload: $payload,
        );

        $result = $adapter->send($message);

        $this->assertSame(1, $result['deliveredTo']);
        $this->assertCount(1, $adapter->captured);

        $request = $adapter->captured[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://hooks.example.test/notify', $request['url']);

        $sent = \json_decode($request['body'], true);
        $this->assertSame($payload, $sent);

        $headerLine = \implode("\n", $request['headers']);
        $this->assertStringContainsString('Content-Type: application/json', $headerLine);
        $this->assertStringContainsString('X-Appwrite-Webhook-Timestamp:', $headerLine);
    }

    public function testSigningSecretProducesHmacSha256Signature(): void
    {
        $adapter = new CapturingWebhook();
        $payload = ['subject' => 'Signed', 'body' => 'B'];
        $secret = 'super-secret';

        $message = new WebhookMessage(
            urls: ['https://hooks.example.test/signed'],
            payload: $payload,
            signingSecret: $secret,
        );

        $adapter->send($message);

        $headers = $adapter->captured[0]['headers'];
        $body = $adapter->captured[0]['body'];

        $timestamp = null;
        $signature = null;
        foreach ($headers as $header) {
            if (\str_starts_with($header, 'X-Appwrite-Webhook-Timestamp: ')) {
                $timestamp = \substr($header, \strlen('X-Appwrite-Webhook-Timestamp: '));
            } elseif (\str_starts_with($header, 'X-Appwrite-Webhook-Signature: ')) {
                $signature = \substr($header, \strlen('X-Appwrite-Webhook-Signature: '));
            }
        }

        $this->assertNotNull($timestamp, 'timestamp header must be present');
        $this->assertNotNull($signature, 'signature header must be present when secret is set');
        $this->assertStringStartsWith('sha256=', $signature);

        $expected = 'sha256=' . \hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $this->assertSame($expected, $signature);
    }

    public function testNoSecretLeavesPayloadUnsigned(): void
    {
        $adapter = new CapturingWebhook();
        $message = new WebhookMessage(
            urls: ['https://hooks.example.test/unsigned'],
            payload: ['x' => 1],
            signingSecret: null,
        );

        $adapter->send($message);

        $headerLine = \implode("\n", $adapter->captured[0]['headers']);
        $this->assertStringNotContainsString('X-Appwrite-Webhook-Signature', $headerLine);
    }

    public function testEmptySecretIsTreatedAsUnsigned(): void
    {
        $adapter = new CapturingWebhook();
        $message = new WebhookMessage(
            urls: ['https://hooks.example.test/empty-secret'],
            payload: ['x' => 1],
            signingSecret: '',
        );

        $adapter->send($message);

        $headerLine = \implode("\n", $adapter->captured[0]['headers']);
        $this->assertStringNotContainsString('X-Appwrite-Webhook-Signature', $headerLine);
    }

    public function testTwoXxIsSuccess(): void
    {
        $adapter = new CapturingWebhook();
        $adapter->response = ['statusCode' => 204, 'response' => '', 'error' => null];
        $message = new WebhookMessage(urls: ['https://hooks.example.test/ok'], payload: []);

        $result = $adapter->send($message);

        $this->assertSame(1, $result['deliveredTo']);
    }

    public function testNonTwoXxSurfacesError(): void
    {
        $adapter = new CapturingWebhook();
        $adapter->response = ['statusCode' => 503, 'response' => 'Server', 'error' => null];
        $message = new WebhookMessage(urls: ['https://hooks.example.test/fail'], payload: []);

        $result = $adapter->send($message);

        $this->assertSame(0, $result['deliveredTo']);
        $error = $result['results'][0]['error'] ?? null;
        $this->assertSame('HTTP 503', $error);
    }

    public function testCurlErrorSurfacesAsResultError(): void
    {
        $adapter = new CapturingWebhook();
        $adapter->response = ['statusCode' => 0, 'response' => null, 'error' => 'connection refused'];
        $message = new WebhookMessage(urls: ['https://hooks.example.test/down'], payload: []);

        $result = $adapter->send($message);

        $this->assertSame(0, $result['deliveredTo']);
        $this->assertSame('connection refused', $result['results'][0]['error']);
    }

    public function testCustomHeadersForwarded(): void
    {
        $adapter = new CapturingWebhook();
        $message = new WebhookMessage(
            urls: ['https://hooks.example.test/with-headers'],
            payload: [],
            headers: ['X-Custom' => 'value'],
        );

        $adapter->send($message);

        $headerLine = \implode("\n", $adapter->captured[0]['headers']);
        $this->assertStringContainsString('X-Custom: value', $headerLine);
    }

    public function testRejectsForeignMessageType(): void
    {
        $adapter = new CapturingWebhook();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid message type.');

        $adapter->send(new \Appwrite\Utopia\Messaging\Messages\Console(recipients: [['userId' => 'u']], title: 't', body: 'b'));
    }
}
