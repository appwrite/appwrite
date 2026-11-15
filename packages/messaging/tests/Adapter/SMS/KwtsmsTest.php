<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\SMS;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\SMS\Kwtsms;
use Utopia\Messaging\Messages\SMS;

final class KwtsmsTest extends TestCase
{
    public function testLimits(): void
    {
        $stub = new KwtsmsStub('user', 'pass');

        $this->assertSame('kwtSMS', $stub->getName());
        $this->assertSame(200, $stub->getMaxMessagesPerRequest());
    }

    public function testRequestShapeAndNumberNormalisation(): void
    {
        $stub = new KwtsmsStub('user', 'pass', 'MY-SENDER', true);
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => [
            'result' => 'OK',
            'msg-id' => 'abc123',
            'numbers' => 3,
            'points-charged' => 3,
            'balance-after' => 97,
        ]];

        $response = $stub->send(new SMS(
            to: ['+96500000000', '0096500000000', '96500000000'],
            content: 'Hello',
            from: '+15557654321',
        ));

        $request = $stub->capturedRequests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://www.kwtsms.com/API/send/', $request['url']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame('user', $request['body']['username']);
        $this->assertSame('pass', $request['body']['password']);
        $this->assertSame('MY-SENDER', $request['body']['sender']);
        $this->assertSame('96500000000,96500000000,96500000000', $request['body']['mobile']);
        $this->assertSame('Hello', $request['body']['message']);
        $this->assertSame('1', $request['body']['test']);

        $this->assertSame(3, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][2]['status']);
        $this->assertSame('+96500000000', $response['results'][0]['recipient']);
    }

    public function testDefaultsAreSenderKwtSmsAndLiveMode(): void
    {
        $stub = new KwtsmsStub('user', 'pass');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['result' => 'OK']];

        $stub->send(new SMS(to: ['965XXXXXXXX'], content: 'Hi', from: 'ignored'));

        $this->assertSame('KWT-SMS', $stub->capturedRequests[0]['body']['sender']);
        $this->assertSame('0', $stub->capturedRequests[0]['body']['test']);
    }

    public function testErrorIsSurfacedForEveryRecipient(): void
    {
        $stub = new KwtsmsStub('user', 'pass');
        $stub->stubResponses[] = ['statusCode' => 403, 'response' => [
            'result' => 'ERROR',
            'code' => 'ERR003',
            'description' => 'Wrong username or password.',
        ]];

        $response = $stub->send(new SMS(to: ['965XXXXXXXX', '965XXXXXXXX'], content: 'Hi', from: 'x'));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('failure', $response['results'][1]['status']);
        $this->assertSame('Wrong username or password.', $response['results'][1]['error']);
        $this->assertSame(3, $response['results'][1]['code']);
        $this->assertFalse($response['results'][1]['permanent']);
    }

    public function testInvalidNumberErrorIsPermanent(): void
    {
        $stub = new KwtsmsStub('user', 'pass');
        $stub->stubResponses[] = ['statusCode' => 400, 'response' => [
            'result' => 'ERROR',
            'code' => 'ERR025',
            'description' => 'No valid numbers found.',
        ]];

        $response = $stub->send(new SMS(to: ['12'], content: 'Hi', from: 'x'));

        $this->assertTrue($response['results'][0]['permanent']);
    }

    public function testTransportFailureFallsBackToClientError(): void
    {
        $stub = new KwtsmsStub('user', 'pass');
        $stub->stubResponses[] = ['statusCode' => 0, 'response' => null, 'error' => 'timeout'];

        $response = $stub->send(new SMS(to: ['965XXXXXXXX'], content: 'Hi', from: 'x'));

        $this->assertSame('timeout', $response['results'][0]['error']);
    }

    public function testMoreThan200RecipientsIsRejected(): void
    {
        $stub = new KwtsmsStub('user', 'pass');

        $this->expectException(\Utopia\Messaging\Exception\InvalidArgumentException::class);
        $stub->send(new SMS(to: array_fill(0, 201, '965XXXXXXXX'), content: 'Hi', from: 'x'));
    }
}

class KwtsmsStub extends Kwtsms
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

        $stub = array_shift($this->stubResponses) ?? ['statusCode' => 200, 'response' => []];

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
