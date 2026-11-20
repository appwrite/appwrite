<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\Bird;
use Utopia\Messaging\Adapter\Email\Bird\Category;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;

final class BirdTest extends TestCase
{
    private const string KEY = 'bk_eu1_Ab3xKq9mP2wR5tY8uI1oL4nJ';

    /**
     * What Bird answers when a recipient is at a domain it does not deliver to.
     */
    private const array RECIPIENT_REFUSED = ['statusCode' => 422, 'response' => ['error' => [
        'type' => 'validation_error',
        'code' => 'E04011',
        'name' => 'RecipientDomainNotAllowed',
        'message' => "'b@example.com' cannot receive mail.",
        'param' => 'to',
    ]]];

    /**
     * What Bird answers when the sender's domain is not verified in the workspace.
     */
    private const array SENDER_REFUSED = ['statusCode' => 422, 'response' => ['error' => [
        'type' => 'validation_error',
        'code' => 'E04006',
        'name' => 'DomainNotVerified',
        'message' => 'The from address uses a domain that is not verified in this workspace.',
    ]]];

    public function testHostFollowsTheKeyRegion(): void
    {
        foreach (['eu1', 'us1'] as $region) {
            $stub = new BirdStub("bk_{$region}_Ab3xKq9mP2wR5tY8uI1oL4nJ");

            $stub->send($this->email(['a@example.com']));

            $this->assertSame("https://{$region}.platform.bird.com/v1/email/messages", $stub->capturedRequests[0]['url']);
        }
    }

    public function testKeyWithoutRegionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bk_{region}_');

        new Bird('live_Ab3xKq9mP2wR5tY8uI1oL4nJ');
    }

    public function testEndpointOverrideSkipsRegionParsing(): void
    {
        $stub = new BirdStub('not-a-bird-key', endpoint: 'http://127.0.0.1:8080/');

        $stub->send($this->email(['a@example.com']));

        $this->assertSame('http://127.0.0.1:8080/v1/email/messages', $stub->capturedRequests[0]['url']);
    }

    public function testSingleRecipientUsesTheMessageEndpoint(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['id' => 'em_01', 'status' => 'accepted']];

        $response = $stub->send(new Email(
            to: ['a@example.com'],
            subject: 'Your receipt',
            content: 'Thanks for your order.',
            fromName: 'Acme',
            fromEmail: 'billing@acme.dev',
            replyToName: 'Support',
            replyToEmail: 'support@acme.dev',
        ));

        $this->assertCount(1, $stub->capturedRequests);
        $request = $stub->capturedRequests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://eu1.platform.bird.com/v1/email/messages', $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::KEY, $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame([
            'from' => ['email' => 'billing@acme.dev', 'name' => 'Acme'],
            'to' => [['email' => 'a@example.com']],
            'subject' => 'Your receipt',
            'text' => 'Thanks for your order.',
            'category' => 'transactional',
            'reply_to' => [['email' => 'support@acme.dev', 'name' => 'Support']],
        ], $request['body']);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('a@example.com', $response['results'][0]['recipient']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testSeveralRecipientsUseTheBatchEndpoint(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['data' => [
            ['id' => 'em_01', 'status' => 'accepted', 'category' => 'transactional'],
            ['id' => 'em_02', 'status' => 'accepted', 'category' => 'transactional'],
        ]]];

        $response = $stub->send(new Email(
            to: ['a@example.com', 'b@example.com'],
            subject: 'Subject',
            content: '<p>Body</p>',
            fromName: 'Sender',
            fromEmail: 'from@acme.dev',
            cc: ['cc@example.com'],
            bcc: [['email' => 'bcc@example.com', 'name' => 'Audit']],
            html: true,
        ));

        $this->assertCount(1, $stub->capturedRequests);
        $request = $stub->capturedRequests[0];
        $this->assertSame('https://eu1.platform.bird.com/v1/email/batches', $request['url']);
        $this->assertSame(['messages'], array_keys($request['body']));
        $this->assertCount(2, $request['body']['messages']);

        // Each recipient gets a message of their own, with the copies on every one.
        foreach (['a@example.com', 'b@example.com'] as $index => $recipient) {
            $email = $request['body']['messages'][$index];
            $this->assertSame([['email' => $recipient]], $email['to']);
            $this->assertSame([['email' => 'cc@example.com']], $email['cc']);
            $this->assertSame([['email' => 'bcc@example.com', 'name' => 'Audit']], $email['bcc']);
            $this->assertSame('<p>Body</p>', $email['html']);
            $this->assertArrayNotHasKey('text', $email);
        }

        $this->assertSame(2, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('success', $response['results'][1]['status']);
    }

    public function testCategoryIsSentWithEveryMessage(): void
    {
        $stub = new BirdStub(self::KEY, Category::MARKETING);

        $stub->send($this->email(['a@example.com', 'b@example.com']));

        foreach ($stub->capturedRequests[0]['body']['messages'] as $email) {
            $this->assertSame('marketing', $email['category']);
        }
    }

    public function testDisplayNamesAreSentUnquoted(): void
    {
        $stub = new BirdStub(self::KEY);

        $stub->send(new Email(
            to: [['email' => 'a@example.com', 'name' => 'Doe, John <JD>']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Acme "Labs"',
            fromEmail: 'from@acme.dev',
        ));

        $body = $stub->capturedRequests[0]['body'];

        $this->assertSame([['email' => 'a@example.com', 'name' => 'Doe, John <JD>']], $body['to']);
        $this->assertSame(['email' => 'from@acme.dev', 'name' => 'Acme "Labs"'], $body['from']);
    }

    public function testEmptyReplyToOmitsTheField(): void
    {
        $stub = new BirdStub(self::KEY);

        $stub->send(new Email(
            to: ['a@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@acme.dev',
            replyToEmail: '',
        ));

        $this->assertArrayNotHasKey('reply_to', $stub->capturedRequests[0]['body']);
    }

    public function testMessageHeadersAreSentPerEmail(): void
    {
        $stub = new BirdStub(self::KEY);

        $stub->send(new Email(
            to: ['a@example.com', 'b@example.com'],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@acme.dev',
            headers: ['List-Unsubscribe' => '<https://acme.dev/u>'],
        ));

        foreach ($stub->capturedRequests[0]['body']['messages'] as $email) {
            $this->assertSame(['List-Unsubscribe' => '<https://acme.dev/u>'], $email['headers']);
        }
    }

    public function testWithoutExtrasTheirFieldsAreOmitted(): void
    {
        $stub = new BirdStub(self::KEY);

        $stub->send($this->email(['a@example.com']));

        $body = $stub->capturedRequests[0]['body'];

        foreach (['cc', 'bcc', 'headers', 'attachments'] as $field) {
            $this->assertArrayNotHasKey($field, $body);
        }
    }

    public function testAttachmentsAreSentBase64Encoded(): void
    {
        $stub = new BirdStub(self::KEY);
        $path = tempnam(sys_get_temp_dir(), 'bird');
        file_put_contents($path, 'from a file');

        try {
            $response = $stub->send($this->email(['a@example.com', 'b@example.com'], attachments: [
                new Attachment(name: 'note.txt', path: '', type: 'text/plain', content: 'hello'),
                new Attachment(name: 'file.txt', path: $path, type: 'text/plain'),
            ]));
        } finally {
            unlink($path);
        }

        $this->assertCount(1, $stub->capturedRequests);
        $this->assertSame('https://eu1.platform.bird.com/v1/email/batches', $stub->capturedRequests[0]['url']);

        foreach ($stub->capturedRequests[0]['body']['messages'] as $email) {
            $this->assertSame([
                ['filename' => 'note.txt', 'content' => base64_encode('hello'), 'content_type' => 'text/plain'],
                ['filename' => 'file.txt', 'content' => base64_encode('from a file'), 'content_type' => 'text/plain'],
            ], $email['attachments']);
        }

        $this->assertSame(2, $response['deliveredTo']);
    }

    public function testMessageThatFillsTheSizeLimitIsSent(): void
    {
        $stub = new BirdStub(self::KEY);

        // 14,999,997 bytes encode to 19,999,996, which leaves room for the 4 byte body and nothing else.
        $response = $stub->send($this->email(['a@example.com'], content: 'Body', attachments: [new Attachment(
            name: 'large.bin',
            path: '',
            type: 'application/octet-stream',
            content: str_repeat('x', 14_999_997),
        )]));

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertCount(1, $stub->capturedRequests);
    }

    public function testBodyCountsTowardTheSizeLimit(): void
    {
        $stub = new BirdStub(self::KEY);

        try {
            // 15 MB of files encode to exactly 20 MB, so the body no longer fits.
            $stub->send($this->email(['a@example.com'], content: 'Body', attachments: [new Attachment(
                name: 'large.bin',
                path: '',
                type: 'application/octet-stream',
                content: str_repeat('x', 15_000_000),
            )]));
            $this->fail('A message above the size limit was sent.');
        } catch (\Exception $error) {
            $this->assertSame(
                'Message size exceeds 20000000 bytes: the body and the base64 encoded attachments take 20000004 bytes',
                $error->getMessage(),
            );
        }

        $this->assertSame([], $stub->capturedRequests);
    }

    public function testBatchesAreSplitToFitTheBodyCap(): void
    {
        $stub = new BirdStub(self::KEY);
        $recipients = array_map(fn (int $i): string => "user{$i}@example.com", range(1, 100));

        // 100 messages of 600 KiB are 61 MB, and 32 of them are the most that fit in 20 MB.
        $response = $stub->send($this->email($recipients, content: str_repeat('x', 600 * 1024)));

        $sent = [];
        foreach ($stub->capturedRequests as $request) {
            $this->assertSame('https://eu1.platform.bird.com/v1/email/batches', $request['url']);
            $this->assertLessThanOrEqual(20 * 1000 * 1000, \strlen(json_encode($request['body'], JSON_THROW_ON_ERROR)));

            foreach ($request['body']['messages'] as $email) {
                $sent[] = $email['to'][0]['email'];
            }
        }

        $this->assertSame([32, 32, 32, 4], array_map(
            fn (array $request): int => \count($request['body']['messages']),
            $stub->capturedRequests,
        ));
        $this->assertSame($recipients, $sent);
        $this->assertSame(100, $response['deliveredTo']);
    }

    public function testRefusedRecipientDoesNotFailTheRestOfTheBatch(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = self::RECIPIENT_REFUSED;
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['id' => 'em_01']];
        $stub->stubResponses[] = self::RECIPIENT_REFUSED;
        $stub->stubResponses[] = ['statusCode' => 202, 'response' => ['id' => 'em_03']];

        $response = $stub->send($this->email(['a@acme.dev', 'b@example.com', 'c@acme.dev']));

        // Bird does not say which message it refused, so each is sent again on its own.
        $this->assertSame([
            'https://eu1.platform.bird.com/v1/email/batches',
            'https://eu1.platform.bird.com/v1/email/messages',
            'https://eu1.platform.bird.com/v1/email/messages',
            'https://eu1.platform.bird.com/v1/email/messages',
        ], array_column($stub->capturedRequests, 'url'));
        $this->assertSame([['email' => 'b@example.com']], $stub->capturedRequests[2]['body']['to']);

        $this->assertSame(2, $response['deliveredTo']);
        $this->assertSame(
            ['a@acme.dev' => 'success', 'b@example.com' => 'failure', 'c@acme.dev' => 'success'],
            array_column($response['results'], 'status', 'recipient'),
        );
        $this->assertSame("E04011 'b@example.com' cannot receive mail.", $response['results'][1]['error']);
    }

    public function testRefusalOfWhatMessagesShareFailsEveryRecipient(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = self::SENDER_REFUSED;

        $response = $stub->send($this->email(['a@acme.dev', 'b@acme.dev']));

        $this->assertCount(1, $stub->capturedRequests);
        $this->assertSame(0, $response['deliveredTo']);
        foreach ($response['results'] as $result) {
            $this->assertSame('failure', $result['status']);
            $this->assertSame(
                'E04006 The from address uses a domain that is not verified in this workspace.',
                $result['error'],
            );
        }
    }

    public function testRefusalOfTheOnlyRecipientIsInvalidInput(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = self::RECIPIENT_REFUSED;

        try {
            $stub->send($this->email(['b@example.com']));
            $this->fail('Expected invalid input');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(InvalidArgumentException::PROVIDER_REJECTED, $exception->getType());
            $this->assertSame('b@example.com', $exception->getValue());
            $this->assertSame("E04011 'b@example.com' cannot receive mail.", $exception->getMessage());
        }
    }

    public function testValidationDetailsAreSurfaced(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 422, 'response' => ['error' => [
            'type' => 'validation_error',
            'code' => 'E01001',
            'name' => 'ValidationError',
            'message' => 'Request has 1 validation error.',
            'details' => [['param' => 'headers.Received', 'message' => 'this header is reserved']],
        ]]];

        $response = $stub->send($this->email(['a@acme.dev', 'b@acme.dev']));

        $this->assertSame(
            'E01001 Request has 1 validation error. headers.Received: this header is reserved',
            $response['results'][0]['error'],
        );
    }

    public function testRateLimitIsReportedAndNotThrown(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 429, 'response' => ['error' => [
            'type' => 'rate_limit_error',
            'code' => 'E01003',
            'name' => 'RateLimited',
            'message' => 'Too many requests. Please retry after the period indicated in the Retry-After header.',
        ]]];

        $response = $stub->send($this->email(['a@acme.dev']));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('failure', $response['results'][0]['status']);
        $this->assertSame(
            'E01003 Too many requests. Please retry after the period indicated in the Retry-After header.',
            $response['results'][0]['error'],
        );
    }

    public function testTransportFailureIsReported(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 0, 'response' => null, 'error' => 'Connection refused'];

        $response = $stub->send($this->email(['a@acme.dev']));

        $this->assertSame('failure', $response['results'][0]['status']);
        $this->assertSame('Connection refused', $response['results'][0]['error']);
    }

    public function testAnswerWithoutAnEnvelopeNamesTheStatus(): void
    {
        $stub = new BirdStub(self::KEY);
        $stub->stubResponses[] = ['statusCode' => 502, 'response' => '<html>Bad Gateway</html>'];

        $response = $stub->send($this->email(['a@acme.dev']));

        $this->assertSame('Bird answered with HTTP status 502.', $response['results'][0]['error']);
    }

    public function testMessageWithoutToRecipientsIsRefused(): void
    {
        $stub = new BirdStub(self::KEY);

        try {
            $stub->send($this->email([], bcc: ['hidden@acme.dev']));
            $this->fail('Expected invalid input');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(InvalidArgumentException::RECIPIENT_EMPTY, $exception->getType());
            $this->assertSame([], $stub->capturedRequests);
        }
    }

    public function testMoreThanTheBatchLimitIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bird can only send 100 messages per request.');

        $stub = new BirdStub(self::KEY);

        $stub->send($this->email(array_map(fn (int $i): string => "user{$i}@example.com", range(0, 100))));
    }

    /**
     * @param  array<string>  $to
     * @param  array<string>|null  $bcc
     * @param  array<Attachment>|null  $attachments
     */
    private function email(array $to, string $content = 'Body', ?array $bcc = null, ?array $attachments = null): Email
    {
        return new Email(
            to: $to,
            subject: 'Subject',
            content: $content,
            fromName: 'Sender',
            fromEmail: 'from@acme.dev',
            replyToEmail: '',
            bcc: $bcc,
            attachments: $attachments,
        );
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
