<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\Resend;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;

final class ResendRoutingTest extends TestCase
{
    public function testWithoutAttachmentsUsesBatchEndpoint(): void
    {
        $stub = new ResendStub('test-key');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => []];

        $message = new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertCount(1, $stub->capturedRequests);
        $this->assertEquals('https://api.resend.com/emails/batch', $stub->capturedRequests[0]['url']);
        $this->assertCount(2, $stub->capturedRequests[0]['body']);
        $this->assertArrayNotHasKey('attachments', $stub->capturedRequests[0]['body'][0]);
        $this->assertEquals(2, $response['deliveredTo']);
    }

    public function testWithAttachmentsUsesSingleEndpointPerRecipient(): void
    {
        $stub = new ResendStub('test-key');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['id' => 'one']];
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['id' => 'two']];

        $message = new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            attachments: [new Attachment(
                name: 'note.txt',
                path: '',
                type: 'text/plain',
                content: 'hello',
            )],
        );

        $response = $stub->send($message);

        $this->assertCount(2, $stub->capturedRequests);

        foreach ($stub->capturedRequests as $request) {
            $this->assertEquals('https://api.resend.com/emails', $request['url']);
            $this->assertArrayHasKey('attachments', $request['body']);
            $this->assertCount(1, $request['body']['attachments']);
            $this->assertEquals('note.txt', $request['body']['attachments'][0]['filename']);
            $this->assertEquals('text/plain', $request['body']['attachments'][0]['content_type']);
            $this->assertEquals(base64_encode('hello'), $request['body']['attachments'][0]['content']);
        }

        $this->assertEquals(2, $response['deliveredTo']);
    }

    public function testPartialFailureWithAttachmentsAggregatesResults(): void
    {
        $stub = new ResendStub('test-key');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['id' => 'one']];
        $stub->stubResponses[] = ['statusCode' => 422, 'response' => ['message' => 'Invalid recipient']];

        $message = new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            attachments: [new Attachment(
                name: 'note.txt',
                path: '',
                type: 'text/plain',
                content: 'hello',
            )],
        );

        $response = $stub->send($message);

        $this->assertEquals(1, $response['deliveredTo']);
        $this->assertEquals('success', $response['results'][0]['status']);
        $this->assertEquals('failure', $response['results'][1]['status']);
        $this->assertEquals('Invalid recipient', $response['results'][1]['error']);
    }

    public function testDisplayNamesWithSpecialsAreQuoted(): void
    {
        $stub = new ResendStub('test-key');

        $message = new Email(
            to: [['email' => 'a@example.com', 'name' => 'Doe, John <JD>']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Acme "Labs"',
            fromEmail: 'from@example.com',
            replyToName: 'Support',
            replyToEmail: 'support@example.com',
            cc: [['email' => 'cc@example.com', 'name' => 'Plain Name']],
        );

        $stub->send($message);

        $body = $stub->capturedRequests[0]['body'][0];

        $this->assertSame(['"Doe, John <JD>" <a@example.com>'], $body['to']);
        $this->assertSame('"Acme \\"Labs\\"" <from@example.com>', $body['from']);
        $this->assertSame(['Support <support@example.com>'], $body['reply_to']);
        $this->assertSame(['Plain Name <cc@example.com>'], $body['cc']);
    }

    public function testEmptyReplyToOmitsTheHeader(): void
    {
        $stub = new ResendStub('test-key');

        $message = new Email(
            to: ['a@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            replyToEmail: '',
        );

        $stub->send($message);

        $this->assertArrayNotHasKey('reply_to', $stub->capturedRequests[0]['body'][0]);
    }

    public function testUnprocessableResponseIsInvalidInput(): void
    {
        $stub = new ResendStub('test-key');
        $stub->stubResponses[] = [
            'statusCode' => 422,
            'response' => ['statusCode' => 422, 'name' => 'validation_error', 'message' => 'Invalid `to` field.'],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            attachments: [new Attachment(name: 'note.txt', path: '', type: 'text/plain', content: 'hello')],
        );

        try {
            $stub->send($message);
            $this->fail('Expected invalid input');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(InvalidArgumentException::PROVIDER_REJECTED, $exception->getType());
            $this->assertSame('a@example.com', $exception->getValue());
            $this->assertSame('Invalid `to` field.', $exception->getMessage());
        }
    }

    public function testAttachmentExceedingMaxSizeThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Total attachment size exceeds');

        $stub = new ResendStub('test-key');

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            attachments: [new Attachment(
                name: 'large.bin',
                path: '',
                type: 'application/octet-stream',
                content: str_repeat('x', 40 * 1024 * 1024 + 1),
            )],
        );

        $stub->send($message);
    }

    public function testMessageHeadersAreSentPerEmail(): void
    {
        $stub = new ResendStub('test-key');

        $stub->send(new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            headers: ['List-Unsubscribe' => '<https://example.test/u>'],
        ));

        foreach ($stub->capturedRequests[0]['body'] as $email) {
            $this->assertSame(['List-Unsubscribe' => '<https://example.test/u>'], $email['headers']);
        }
    }

    public function testWithoutMessageHeadersOmitsTheField(): void
    {
        $stub = new ResendStub('test-key');

        $stub->send(new Email(
            to: ['a@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        ));

        $this->assertArrayNotHasKey('headers', $stub->capturedRequests[0]['body'][0]);
    }
}

class ResendStub extends Resend
{
    /**
     * @var array<array{url: string, method: string, headers: array<string>, body: mixed}>
     */
    public array $capturedRequests = [];

    /**
     * @var array<array{statusCode: int, response: array<string, mixed>|string|null}>
     */
    public array $stubResponses = [];

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
            'error' => null,
            'errorCode' => 0,
        ];
    }
}
