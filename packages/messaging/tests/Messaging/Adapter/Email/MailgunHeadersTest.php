<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Messages\Email;

final class MailgunHeadersTest extends TestCase
{
    public function testMessageHeadersAreSentAsHeaderFields(): void
    {
        $stub = new MailgunStub('key', 'example.com');

        $stub->send(new Email(
            to: ['a@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            headers: [
                'List-Unsubscribe' => '<https://example.test/u>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ));

        $body = $stub->capturedRequests[0]['body'];
        $this->assertSame('<https://example.test/u>', $body['h:List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $body['h:List-Unsubscribe-Post']);
    }
}

class MailgunStub extends Mailgun
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

        return ['url' => $url, 'statusCode' => 200, 'response' => [], 'headers' => [], 'error' => null, 'errorCode' => 0];
    }
}
