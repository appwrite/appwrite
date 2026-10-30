<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\SMS;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\SMS\TextKit;
use Utopia\Messaging\Messages\SMS;

final class TextKitTest extends TestCase
{
    private const string KEY = 'ssms_sk_test_Ab3xKq9mP2wR5tY8';

    public function testSendsTheDocumentedRequest(): void
    {
        $stub = new TextKitStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 201, 'response' => ['id' => 'msg_01', 'status' => 'sent']];

        $response = $stub->send(new SMS(
            to: ['14155550100'],
            content: 'Your code is 123456',
            from: '+15557654321',
        ));

        $this->assertCount(1, $stub->capturedRequests);
        $request = $stub->capturedRequests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.jointextkit.com/v1/messages', $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::KEY, $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame([
            'to' => '+14155550100',
            'body' => 'Your code is 123456',
            'from' => '+15557654321',
        ], $request['body']);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('sms', $response['type']);
        $this->assertSame('+14155550100', $response['results'][0]['recipient']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testConfiguredSenderWinsOverTheMessageSender(): void
    {
        $stub = new TextKitStub(self::KEY, from: '+15550001111');

        $stub->send(new SMS(to: ['+14155550100'], content: 'Hi', from: '+15557654321'));

        $this->assertSame('+15550001111', $stub->capturedRequests[0]['body']['from']);
    }

    public function testSenderIsOmittedWhenThereIsNone(): void
    {
        $stub = new TextKitStub(self::KEY);

        $stub->send(new SMS(to: ['+14155550100'], content: 'Hi'));

        $this->assertArrayNotHasKey('from', $stub->capturedRequests[0]['body']);
    }

    public function testAcceptedButQueuedCountsAsDelivered(): void
    {
        $stub = new TextKitStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['id' => 'msg_01', 'status' => 'queued']];

        $response = $stub->send(new SMS(to: ['+14155550100'], content: 'Hi', from: '+15557654321'));

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testErrorEnvelopeIsReported(): void
    {
        $stub = new TextKitStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 403, 'response' => ['error' => [
            'code' => 'recipient_opted_out',
            'message' => 'This number opted out.',
            'request_id' => 'req_01',
        ]]];

        $response = $stub->send(new SMS(to: ['+14155550100'], content: 'Hi', from: '+15557654321'));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('failure', $response['results'][0]['status']);
        $this->assertSame('recipient_opted_out: This number opted out.', $response['results'][0]['error']);
    }

    public function testTransportFailureIsReported(): void
    {
        $stub = new TextKitStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 0, 'response' => null, 'error' => 'Connection timed out'];

        $response = $stub->send(new SMS(to: ['+14155550100'], content: 'Hi', from: '+15557654321'));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('Connection timed out', $response['results'][0]['error']);
    }

    public function testUnreadableResponseFallsBackToUnknownError(): void
    {
        $stub = new TextKitStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 502, 'response' => '<html>Bad Gateway</html>'];

        $response = $stub->send(new SMS(to: ['+14155550100'], content: 'Hi', from: '+15557654321'));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('Unknown error.', $response['results'][0]['error']);
    }

    public function testOneRecipientPerRequest(): void
    {
        $this->assertSame(1, new TextKit(self::KEY)->getMaxMessagesPerRequest());
    }
}

class TextKitStub extends TextKit
{
    /**
     * @var array<array{url: string, method: string, headers: array<string>, body: mixed}>
     */
    public array $capturedRequests = [];

    /**
     * @var array<array{statusCode: int, response: array<string, mixed>|string|null, error?: string}>
     */
    public array $stubResponses = [];

    /**
     * @param  array<string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string, errorCode: int}
     */
    #[\Override]
    protected function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $body = null,
        int $timeout = 30,
        int $connectTimeout = 10,
    ): array {
        $this->capturedRequests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        $stub = array_shift($this->stubResponses) ?? ['statusCode' => 201, 'response' => []];

        return [
            'url' => $url,
            'statusCode' => $stub['statusCode'],
            'response' => $stub['response'],
            'headers' => [],
            'error' => $stub['error'] ?? '',
            'errorCode' => 0,
        ];
    }
}
