<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SES;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;

/**
 * Network-free verification of how the SES adapter builds and routes requests:
 * the SendBulkEmail primary path, the auto-created template lifecycle, result
 * parsing, and the SendEmail (Content.Raw) attachment fallback.
 */
final class SESRoutingTest extends TestCase
{
    public function testMaxMessagesPerRequestIsFifty(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');

        $this->assertSame(50, $stub->getMaxMessagesPerRequest());
    }

    public function testWithoutAttachmentsUsesBulkEndpoint(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [
                ['Status' => 'SUCCESS', 'MessageId' => 'a'],
                ['Status' => 'SUCCESS', 'MessageId' => 'b'],
            ]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertCount(1, $stub->capturedRequests);
        $request = $stub->capturedRequests[0];

        $this->assertSame('POST', $request['method']);
        $this->assertStringEndsWith('/v2/email/outbound-bulk-emails', $request['url']);
        $this->assertStringContainsString('email.us-east-1.amazonaws.com', $request['url']);

        // One BulkEmailEntry per recipient, each with a single ToAddresses entry.
        $this->assertCount(2, $request['body']['BulkEmailEntries']);
        $this->assertSame(['a@example.com'], $request['body']['BulkEmailEntries'][0]['Destination']['ToAddresses']);
        $this->assertSame(['b@example.com'], $request['body']['BulkEmailEntries'][1]['Destination']['ToAddresses']);

        // The default content references a template by name.
        $this->assertArrayHasKey('TemplateName', $request['body']['DefaultContent']['Template']);
        $this->assertSame('Sender <from@example.com>', $request['body']['FromEmailAddress']);

        $this->assertSame(2, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('success', $response['results'][1]['status']);
    }

    public function testTemplateNameIsDeterministicForSameContent(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]];
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]];

        $build = fn (): \Utopia\Messaging\Messages\Email => new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Same Subject',
            content: 'Same Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $stub->send($build());
        $stub->send($build());

        $first = $stub->capturedRequests[0]['body']['DefaultContent']['Template']['TemplateName'];
        $second = $stub->capturedRequests[1]['body']['DefaultContent']['Template']['TemplateName'];

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('utopia-', $first);
    }

    public function testTemplateNameDiffersForDifferentContent(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]];
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]];

        $stub->send(new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject A',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        ));

        $stub->send(new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject B',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        ));

        $first = $stub->capturedRequests[0]['body']['DefaultContent']['Template']['TemplateName'];
        $second = $stub->capturedRequests[1]['body']['DefaultContent']['Template']['TemplateName'];

        $this->assertNotSame($first, $second);
    }

    public function testTemplateNotFoundTriggersCreateAndRetry(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');

        // 1) Bulk send: template missing (per-entry status).
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'TEMPLATE_NOT_FOUND']]],
        ];
        // 2) CreateEmailTemplate: created.
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => []];
        // 3) Bulk send retry: success.
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'x']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: '<h1>Body</h1>',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            html: true,
        );

        $response = $stub->send($message);

        $this->assertCount(3, $stub->capturedRequests);
        $this->assertStringEndsWith('/v2/email/outbound-bulk-emails', $stub->capturedRequests[0]['url']);
        $this->assertStringEndsWith('/v2/email/templates', $stub->capturedRequests[1]['url']);
        $this->assertStringEndsWith('/v2/email/outbound-bulk-emails', $stub->capturedRequests[2]['url']);

        // The created template carries the message subject and HTML content.
        $templateBody = $stub->capturedRequests[1]['body'];
        $this->assertSame('Subject', $templateBody['TemplateContent']['Subject']);
        $this->assertSame('<h1>Body</h1>', $templateBody['TemplateContent']['Html']);
        $this->assertArrayNotHasKey('Text', $templateBody['TemplateContent']);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testTopLevelMissingTemplateTriggersCreateAndRetry(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');

        // The real SES v2 response for a missing DefaultContent template is a
        // top-level 400 whose exception type lives only in the x-amzn-ErrorType
        // response header, with a plain {"message": ...} body. The auto-create
        // must trigger off that, not off a per-entry BulkEmailEntryResults
        // status (which SES does not return for a missing default template).
        $stub->stubResponses[] = [
            'statusCode' => 400,
            'headers' => ['x-amzn-errortype' => 'BadRequestException'],
            'response' => ['message' => 'Template utopia-abc123 does not exist.'],
        ];
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => []];
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS', 'MessageId' => 'x']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertCount(3, $stub->capturedRequests);
        $this->assertStringEndsWith('/v2/email/outbound-bulk-emails', $stub->capturedRequests[0]['url']);
        $this->assertStringEndsWith('/v2/email/templates', $stub->capturedRequests[1]['url']);
        $this->assertStringEndsWith('/v2/email/outbound-bulk-emails', $stub->capturedRequests[2]['url']);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testCreateTemplateToleratesAlreadyExists(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');

        // Missing on the first send (top-level 400) ...
        $stub->stubResponses[] = [
            'statusCode' => 400,
            'headers' => ['x-amzn-errortype' => 'BadRequestException'],
            'response' => ['message' => 'Template utopia-abc123 does not exist.'],
        ];
        // ... but a concurrent sender created it first, so CreateEmailTemplate
        // returns AlreadyExistsException (again, type in the header). That must
        // be tolerated rather than surfaced as a failure.
        $stub->stubResponses[] = [
            'statusCode' => 400,
            'headers' => ['x-amzn-errortype' => 'AlreadyExistsException'],
            'response' => ['message' => 'Template utopia-abc123 already exists.'],
        ];
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertCount(3, $stub->capturedRequests);
        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
    }

    public function testTextTemplateUsesTextContent(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'TEMPLATE_NOT_FOUND']]],
        ];
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => []];
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Plain Subject',
            content: 'Plain body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $stub->send($message);

        $templateBody = $stub->capturedRequests[1]['body'];
        $this->assertSame('Plain body', $templateBody['TemplateContent']['Text']);
        $this->assertArrayNotHasKey('Html', $templateBody['TemplateContent']);
    }

    public function testPartialFailureMapsPerRecipientResults(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [
                ['Status' => 'SUCCESS', 'MessageId' => 'ok'],
                ['Status' => 'MESSAGE_REJECTED', 'Error' => 'Email address is not verified'],
            ]],
        ];

        $message = new Email(
            to: [['email' => 'good@example.com'], ['email' => 'bad@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('good@example.com', $response['results'][0]['recipient']);
        $this->assertSame('failure', $response['results'][1]['status']);
        $this->assertSame('bad@example.com', $response['results'][1]['recipient']);
        $this->assertSame('Email address is not verified', $response['results'][1]['error']);
    }

    public function testWholeRequestFailureMarksAllRecipientsFailed(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 400,
            'response' => ['message' => 'The sending domain is not verified'],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertSame(0, $response['deliveredTo']);
        foreach ($response['results'] as $result) {
            $this->assertSame('failure', $result['status']);
            $this->assertSame('The sending domain is not verified', $result['error']);
        }
    }

    public function testFiftyRecipientsProduceSingleBulkRequest(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');

        $entryResults = [];
        $recipients = [];
        for ($i = 0; $i < 50; $i++) {
            $recipients[] = ['email' => "user{$i}@example.com"];
            $entryResults[] = ['Status' => 'SUCCESS'];
        }

        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => $entryResults],
        ];

        $message = new Email(
            to: $recipients,
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertCount(1, $stub->capturedRequests);
        $this->assertCount(50, $stub->capturedRequests[0]['body']['BulkEmailEntries']);
        $this->assertSame(50, $response['deliveredTo']);
    }

    public function testExceedingFiftyRecipientsThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('can only send 50 messages per request');

        $stub = new SESStub('key', 'secret', 'us-east-1');

        $recipients = [];
        for ($i = 0; $i < 51; $i++) {
            $recipients[] = ['email' => "user{$i}@example.com"];
        }

        $message = new Email(
            to: $recipients,
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $stub->send($message);
    }

    public function testWithAttachmentsUsesSendEmailRawPerRecipient(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['MessageId' => 'one']];
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['MessageId' => 'two']];

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
                content: 'hello attachment',
            )],
        );

        $response = $stub->send($message);

        $this->assertCount(2, $stub->capturedRequests);

        foreach ($stub->capturedRequests as $request) {
            $this->assertStringEndsWith('/v2/email/outbound-emails', $request['url']);
            $this->assertArrayHasKey('Raw', $request['body']['Content']);

            $mime = base64_decode((string) $request['body']['Content']['Raw']['Data']);
            $this->assertStringContainsString('Subject: Subject', $mime);
            $this->assertStringContainsString('note.txt', $mime);
            // The attachment content is base64-encoded inside the MIME body.
            $this->assertStringContainsString(base64_encode('hello attachment'), $mime);
        }

        $this->assertSame(2, $response['deliveredTo']);
    }

    public function testAttachmentPartialFailureAggregatesResults(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['MessageId' => 'one']];
        $stub->stubResponses[] = ['statusCode' => 400, 'response' => ['message' => 'Invalid recipient']];

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

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('failure', $response['results'][1]['status']);
        $this->assertSame('Invalid recipient', $response['results'][1]['error']);
    }

    public function testAttachmentExceedingMaxSizeThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Total attachment size exceeds');

        $stub = new SESStub('key', 'secret', 'us-east-1');

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
                content: str_repeat('x', 25 * 1024 * 1024 + 1),
            )],
        );

        $stub->send($message);
    }

    public function testSessionTokenAddsSecurityTokenHeader(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1', 'session-token-value');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $stub->send($message);

        $headers = $stub->capturedRequests[0]['headers'];
        $joined = implode("\n", $headers);

        $this->assertStringContainsString('X-Amz-Security-Token: session-token-value', $joined);
        // The signed headers list in the Authorization header must include it.
        $this->assertStringContainsString('x-amz-security-token', $joined);
    }

    public function testBulkEntriesIncludeCcAndBcc(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            cc: [['email' => 'cc@example.com', 'name' => 'CC Person']],
            bcc: [['email' => 'bcc@example.com']],
        );

        $stub->send($message);

        $destination = $stub->capturedRequests[0]['body']['BulkEmailEntries'][0]['Destination'];

        $this->assertSame(['a@example.com'], $destination['ToAddresses']);
        $this->assertSame(['CC Person <cc@example.com>'], $destination['CcAddresses']);
        $this->assertSame(['bcc@example.com'], $destination['BccAddresses']);
    }

    public function testBulkEntriesOmitCcAndBccWhenAbsent(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $stub->send($message);

        $destination = $stub->capturedRequests[0]['body']['BulkEmailEntries'][0]['Destination'];

        $this->assertArrayNotHasKey('CcAddresses', $destination);
        $this->assertArrayNotHasKey('BccAddresses', $destination);
    }

    public function testDisplayNameWithSpecialCharactersIsQuoted(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Acme, Inc.',
            fromEmail: 'from@example.com',
        );

        $stub->send($message);

        // A name containing RFC 5322 specials must be quoted or SES rejects it.
        $this->assertSame(
            '"Acme, Inc." <from@example.com>',
            $stub->capturedRequests[0]['body']['FromEmailAddress'],
        );
    }

    public function testTemplateNameRespectsSesLengthLimit(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = [
            'statusCode' => 200,
            'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]],
        ];

        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: str_repeat('long subject ', 64),
            content: str_repeat('long body ', 64),
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $stub->send($message);

        $templateName = $stub->capturedRequests[0]['body']['DefaultContent']['Template']['TemplateName'];

        $this->assertLessThanOrEqual(64, \strlen((string) $templateName));
        $this->assertStringStartsWith('utopia-', $templateName);
    }

    public function testSuccessWithoutEntryResultsMarksAllRecipientsFailed(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        // A 2xx whose body carries no BulkEmailEntryResults must not be reported
        // as a delivery, since per-recipient status cannot be confirmed.
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => []];

        $message = new Email(
            to: [['email' => 'a@example.com'], ['email' => 'b@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        );

        $response = $stub->send($message);

        $this->assertSame(0, $response['deliveredTo']);
        foreach ($response['results'] as $result) {
            $this->assertSame('failure', $result['status']);
            $this->assertNotSame('', $result['error']);
        }
    }

    public function testMimeExceedingSesLimitThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MIME message size exceeds SES limit');

        $stub = new SESStub('key', 'secret', 'us-east-1');

        // ~8MB of raw content clears the raw-attachment check (< 10MB) but its
        // base64-encoded MIME exceeds the SES 10MB message limit.
        $message = new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            attachments: [new Attachment(
                name: 'big.bin',
                path: '',
                type: 'application/octet-stream',
                content: str_repeat('x', 8 * 1024 * 1024),
            )],
        );

        $stub->send($message);
    }

    public function testBulkSendCarriesTheMessageHeaders(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]];

        $stub->send(new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            headers: ['List-Unsubscribe' => '<https://example.test/u>'],
        ));

        $this->assertSame(
            [['Name' => 'List-Unsubscribe', 'Value' => '<https://example.test/u>']],
            $stub->capturedRequests[0]['body']['DefaultContent']['Template']['Headers'],
        );
    }

    public function testRawSendCarriesTheMessageHeaders(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['MessageId' => 'a']];

        $stub->send(new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
            attachments: [new Attachment(name: 'note.txt', path: '', type: 'text/plain', content: 'hello')],
            headers: ['List-Unsubscribe' => '<https://example.test/u>'],
        ));

        $raw = base64_decode((string) $stub->capturedRequests[0]['body']['Content']['Raw']['Data']);
        $this->assertStringContainsString("List-Unsubscribe: <https://example.test/u>\r\n", $raw);
    }

    public function testBulkSendWithoutHeadersOmitsTheField(): void
    {
        $stub = new SESStub('key', 'secret', 'us-east-1');
        $stub->stubResponses[] = ['statusCode' => 200, 'response' => ['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]];

        $stub->send(new Email(
            to: [['email' => 'a@example.com']],
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: 'from@example.com',
        ));

        $this->assertArrayNotHasKey('Headers', $stub->capturedRequests[0]['body']['DefaultContent']['Template']);
    }
}

/**
 * Captures the requests the SES adapter would send and returns canned
 * responses, so request building and routing can be asserted without network.
 */
class SESStub extends SES
{
    /**
     * @var array<array{method: string, url: string, headers: array<string>, body: mixed}>
     */
    public array $capturedRequests = [];

    /**
     * @var array<array{statusCode: int, response: array<string, mixed>|string|null, headers?: array<string, string>}>
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
            'headers' => $stub['headers'] ?? [],
            'error' => null,
            'errorCode' => 0,
        ];
    }
}
