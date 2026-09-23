<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Messages\Email;

final class SendgridHeadersTest extends TestCase
{
    public function testMessageHeadersAreSentAsHeadersObject(): void
    {
        $stub = new SendgridStub('key');

        $stub->send(new Email(
            to: ['a@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            headers: ['List-Unsubscribe' => '<https://example.test/u>'],
        ));

        $this->assertSame(['List-Unsubscribe' => '<https://example.test/u>'], $stub->capturedRequests[0]['body']['headers']);
    }

    public function testWithoutMessageHeadersOmitsTheField(): void
    {
        $stub = new SendgridStub('key');

        $stub->send(new Email(
            to: ['a@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        ));

        $this->assertArrayNotHasKey('headers', $stub->capturedRequests[0]['body']);
    }
}

class SendgridStub extends Sendgrid
{
    /**
     * @var array<array{method: string, url: string, headers: array<string>, body: mixed}>
     */
    public array $capturedRequests = [];

    /**
     * @param  array<string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null, errorCode: int}
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
        $this->capturedRequests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return ['url' => $url, 'statusCode' => 202, 'response' => null, 'headers' => [], 'error' => null, 'errorCode' => 0];
    }
}
