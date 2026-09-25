<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\SMS;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Messaging\Adapter\SMS\Bird\Category;
use Utopia\Messaging\Messages\SMS;

final class BirdTest extends TestCase
{
    public function testHostFollowsTheKeyRegion(): void
    {
        $this->assertSame('https://eu1.platform.bird.com', Bird::hostFor('bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ'));
        $this->assertSame('https://us1.platform.bird.com', Bird::hostFor('bk_us1_Ab3xKq9mP2wR5tY8uI1oL4nJ'));
        $this->assertSame('https://ap1.platform.bird.com', Bird::hostFor('bk_ap1_Ab3xKq9mP2wR5tY8uI1oL4nJ'));
    }

    public function testKeyWithoutRegionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bk_{region}_');

        new Bird('live_Ab3xKq9mP2wR5tY8uI1oL4nJ');
    }

    public function testEndpointOverrideSkipsRegionParsing(): void
    {
        $stub = new BirdStub('not-a-bird-key', endpoint: 'http://127.0.0.1:8080/');

        $stub->send(new SMS(to: ['+31612345678'], content: 'Hi', from: 'Bird'));

        $this->assertSame('http://127.0.0.1:8080/v1/sms/messages', $stub->capturedRequests[0]['url']);
    }

    public function testSingleRecipientUsesTheMessageEndpoint(): void
    {
        $stub = new BirdStub('bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ', from: 'Appwrite', category: Category::AUTHENTICATION);
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['id' => 'sms_01', 'status' => 'accepted']];

        $response = $stub->send(new SMS(
            to: ['31612345678'],
            content: 'Your code is 123456',
            from: '+15557654321',
            metadata: ['messageId' => 'msg_1'],
        ));

        $this->assertCount(1, $stub->capturedRequests);
        $request = $stub->capturedRequests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://eu1.platform.bird.com/v1/sms/messages', $request['url']);
        $this->assertContains('Authorization: Bearer bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ', $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame('+31612345678', $request['body']['to']);
        $this->assertSame('Appwrite', $request['body']['from']);
        $this->assertSame('Your code is 123456', $request['body']['text']);
        $this->assertSame('authentication', $request['body']['category']);
        $this->assertSame(['messageId' => 'msg_1'], $request['body']['metadata']);
        $this->assertCount(5, $request['body']);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('+31612345678', $response['results'][0]['recipient']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testSeveralRecipientsUseTheBatchEndpoint(): void
    {
        $stub = new BirdStub('bk_us1_Ab3xKq9mP2wR5tY8uI1oL4nJ');
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['data' => [], 'summary' => ['accepted_count' => 2]]];

        $response = $stub->send(new SMS(
            to: ['+14155550100', '+14155550101'],
            content: 'Hello',
            from: '+15557654321',
        ));

        $request = $stub->capturedRequests[0];
        $this->assertSame('https://us1.platform.bird.com/v1/sms/batches', $request['url']);
        $this->assertCount(2, $request['body']['messages']);
        $this->assertSame('+14155550100', $request['body']['messages'][0]['to']);
        $this->assertSame('+14155550101', $request['body']['messages'][1]['to']);
        $this->assertSame('+15557654321', $request['body']['messages'][0]['from']);
        $this->assertSame('transactional', $request['body']['messages'][0]['category']);
        $this->assertArrayNotHasKey('metadata', $request['body']['messages'][0]);

        $this->assertSame(2, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('success', $response['results'][1]['status']);
    }

    public function testErrorEnvelopeIsSurfacedForEveryRecipient(): void
    {
        $stub = new BirdStub('bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ');
        $stub->stubResponses[] = ['statusCode' => 422, 'response' => ['error' => [
            'type' => 'validation_error',
            'code' => 'E12020',
            'name' => 'SMSDestinationNotEnabled',
            'message' => 'This destination country is not enabled for your workspace.',
            'details' => [['param' => 'to', 'message' => 'country GB is not enabled']],
        ]]];

        $response = $stub->send(new SMS(to: ['+447700900000', '+447700900001'], content: 'Hello', from: 'Bird'));

        $this->assertSame(0, $response['deliveredTo']);
        foreach ($response['results'] as $result) {
            $this->assertSame('failure', $result['status']);
            $this->assertSame(
                'E12020 This destination country is not enabled for your workspace. to: country GB is not enabled',
                $result['error'],
            );
        }
    }

    public function testTransportFailureIsReported(): void
    {
        $stub = new BirdStub('bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ');
        $stub->stubResponses[] = ['statusCode' => 0, 'response' => null, 'error' => 'Connection refused'];

        $response = $stub->send(new SMS(to: ['+31612345678'], content: 'Hello', from: 'Bird'));

        $this->assertSame('failure', $response['results'][0]['status']);
        $this->assertSame('Connection refused', $response['results'][0]['error']);
    }

    public function testMoreThanTheBatchLimitIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Bird can only send 100 messages per request.');

        $stub = new BirdStub('bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ');
        $recipients = array_map(fn (int $i): string => \sprintf('+3161234%04d', $i), range(0, 100));

        $stub->send(new SMS(to: $recipients, content: 'Hello', from: 'Bird'));
    }
}

class BirdStub extends Bird
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

        $stub = array_shift($this->stubResponses) ?? ['statusCode' => 202, 'response' => []];

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
