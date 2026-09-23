<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Tests\Adapter\Base;

final class SMTPTest extends Base
{
    public function testSendEmail(): void
    {
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
        );

        $to = 'tester@localhost.test';
        $subject = 'Test Subject';
        $content = 'Test Content';
        $fromName = 'Test Sender';
        $fromEmail = 'sender@localhost.test';

        $message = new Email(
            to: [$to],
            subject: $subject,
            content: $content,
            fromName: $fromName,
            fromEmail: $fromEmail,
        );

        $response = $sender->send($message);

        $lastEmail = $this->getLastEmail();

        $this->assertResponse($response);
        $this->assertEquals($to, $lastEmail['to'][0]['address']);
        $this->assertEquals($fromEmail, $lastEmail['from'][0]['address']);
        $this->assertEquals($subject, $lastEmail['subject']);
        $this->assertSame($content, trim((string) $lastEmail['text']));
    }

    public function testSendEmailWithHeaders(): void
    {
        $sender = new SMTP(host: '127.0.0.1', port: 11025);

        $response = $sender->send(new Email(
            to: ['tester@localhost.test'],
            subject: 'Header Subject',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
            headers: [
                'List-Unsubscribe' => '<https://example.test/u?token=abc>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ));

        $lastEmail = $this->getLastEmail();

        $this->assertResponse($response);
        $this->assertSame('<https://example.test/u?token=abc>', $lastEmail['headers']['list-unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $lastEmail['headers']['list-unsubscribe-post']);
    }

    public function testSendEmailWithAttachment(): void
    {
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
        );

        $to = 'tester@localhost.test';
        $subject = 'Test Subject';
        $content = 'Test Content';
        $fromName = 'Test Sender';
        $fromEmail = 'sender@localhost.test';

        $message = new Email(
            to: [$to],
            subject: $subject,
            content: $content,
            fromName: $fromName,
            fromEmail: $fromEmail,
            attachments: [new Attachment(
                name: 'image.png',
                path: __DIR__ . '/../../../assets/image.png',
                type: 'image/png',
            )],
        );

        $response = $sender->send($message);

        $lastEmail = $this->getLastEmail();

        $this->assertResponse($response);
        $this->assertEquals($to, $lastEmail['to'][0]['address']);
        $this->assertEquals($fromEmail, $lastEmail['from'][0]['address']);
        $this->assertEquals($subject, $lastEmail['subject']);
        $this->assertSame($content, trim((string) $lastEmail['text']));
    }

    public function testSendEmailOnlyBCC(): void
    {
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
        );

        $subject = 'Test Subject';
        $content = 'Test Content';
        $fromName = 'Test Sender';
        $fromEmail = 'sender@localhost.test';
        $bcc = [
            [
                'email' => 'tester2@localhost.test',
                'name' => 'Test Recipient 2',
            ],
        ];

        $message = new Email(
            to: [],
            subject: $subject,
            content: $content,
            fromName: $fromName,
            fromEmail: $fromEmail,
            bcc: $bcc,
        );

        $response = $sender->send($message);

        $lastEmail = $this->getLastEmail();

        $this->assertResponse($response);
        $this->assertEquals($fromEmail, $lastEmail['from'][0]['address']);
        $this->assertEquals($subject, $lastEmail['subject']);
        $this->assertSame($content, trim((string) $lastEmail['text']));
    }

    public function testAttachmentWithStringContent(): void
    {
        $content = 'Hello, this is raw file content.';
        $attachment = new Attachment(
            name: 'readme.txt',
            path: '',
            type: 'text/plain',
            content: $content,
        );

        $this->assertSame('readme.txt', $attachment->getName());
        $this->assertSame('', $attachment->getPath());
        $this->assertSame('text/plain', $attachment->getType());
        $this->assertSame($content, $attachment->getContent());
    }

    public function testAttachmentWithoutStringContentDefaultsToNull(): void
    {
        $attachment = new Attachment(
            name: 'image.png',
            path: '/tmp/image.png',
            type: 'image/png',
        );

        $this->assertNull($attachment->getContent());
    }

    public function testSMTPConstructorWithKeepAliveAndTimelimit(): void
    {
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
            keepAlive: true,
            timelimit: 60,
        );

        $this->assertInstanceOf(SMTP::class, $sender);
        $this->assertSame('SMTP', $sender->getName());
    }

    public function testSMTPConstructorDefaultsAreBackwardsCompatible(): void
    {
        // Existing call signature still works without new params
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
        );

        $this->assertInstanceOf(SMTP::class, $sender);
    }

    public function testSendEmailWithStringAttachment(): void
    {
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
        );

        $to = 'tester@localhost.test';
        $subject = 'String Attachment Test';
        $content = 'Test with string attachment';
        $fromName = 'Test Sender';
        $fromEmail = 'sender@localhost.test';

        $message = new Email(
            to: [$to],
            subject: $subject,
            content: $content,
            fromName: $fromName,
            fromEmail: $fromEmail,
            attachments: [new Attachment(
                name: 'note.txt',
                path: '',
                type: 'text/plain',
                content: 'This is inline content',
            )],
        );

        $response = $sender->send($message);

        $lastEmail = $this->getLastEmail();

        $this->assertResponse($response);
        $this->assertEquals($to, $lastEmail['to'][0]['address']);
        $this->assertEquals($subject, $lastEmail['subject']);
    }

    public function testSendEmailWithKeepAlive(): void
    {
        $sender = new SMTP(
            host: '127.0.0.1',
            port: 11025,
            keepAlive: true,
            timelimit: 15,
        );

        $to = 'tester@localhost.test';
        $fromEmail = 'sender@localhost.test';

        // Send first message
        $message1 = new Email(
            to: [$to],
            subject: 'KeepAlive Test 1',
            content: 'First message',
            fromName: 'Test',
            fromEmail: $fromEmail,
        );

        $response1 = $sender->send($message1);
        $this->assertResponse($response1);

        // Send second message — should reuse the open connection
        $message2 = new Email(
            to: [$to],
            subject: 'KeepAlive Test 2',
            content: 'Second message',
            fromName: 'Test',
            fromEmail: $fromEmail,
        );

        $response2 = $sender->send($message2);
        $this->assertResponse($response2);

        $sender->disconnect();
    }

    public function testReportsEachRecipientSeparately(): void
    {
        $sender = new SMTP(host: '127.0.0.1', port: 11025);

        $response = $sender->send(new Email(
            to: ['tester@localhost.test', 'tester2@localhost.test'],
            subject: 'Per recipient',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
        ));

        $this->assertResponse($response, deliveredTo: 2);

        $recipients = array_column($response['results'], 'recipient');
        $this->assertSame(['tester@localhost.test', 'tester2@localhost.test'], $recipients);

        foreach ($response['results'] as $result) {
            $this->assertSame('', $result['error']);
        }
    }

    public function testReportsWhyAServerCouldNotBeReached(): void
    {
        // Nothing is listening on port 1, so the failure has to reach every
        // recipient rather than being swallowed.
        $sender = new SMTP(host: '127.0.0.1', port: 1, timeout: 2, timelimit: 2);

        $response = $sender->send(new Email(
            to: ['tester@localhost.test'],
            subject: 'Unreachable',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
        ));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('tester@localhost.test', $response['results'][0]['recipient']);
        $this->assertStringContainsString('127.0.0.1:1', (string) $response['results'][0]['error']);
    }

    public function testTreatsTheLegacyZeroCredentialAsAbsent(): void
    {
        // The adapter this replaces disabled authentication for a literal "0",
        // an artefact of empty(). A server with no AUTH refuses the command, so
        // sending at all proves none was attempted.
        $sender = new SMTP(host: '127.0.0.1', port: 11025, username: '0', password: '0');

        $response = $sender->send(new Email(
            to: ['tester@localhost.test'],
            subject: 'Zero credentials',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
        ));

        $this->assertResponse($response);
    }

    public function testTriesEveryHostItWasGiven(): void
    {
        // The first host answers nothing; the list is walked in order until one
        // does, which is the behaviour the host string has always promised.
        $sender = new SMTP(host: '127.0.0.1:1;127.0.0.1:11025', timeout: 2, timelimit: 2);

        $response = $sender->send(new Email(
            to: ['tester@localhost.test'],
            subject: 'Failover',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
        ));

        $this->assertResponse($response);
        $this->assertSame('Failover', $this->getLastEmail()['subject']);
    }
}
