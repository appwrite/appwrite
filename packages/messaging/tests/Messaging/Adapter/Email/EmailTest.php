<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use Utopia\Messaging\Adapter\Email\Mock;
use Utopia\Messaging\Messages\Email;
use Utopia\Tests\Adapter\Base;

final class EmailTest extends Base
{
    public function testSendEmail(): void
    {
        $sender = new Mock(host: '127.0.0.1', port: 11025);

        $to = 'tester@localhost.test';
        $subject = 'Test Subject';
        $content = 'Test Content';
        $fromName = 'Test Sender';
        $fromEmail = 'sender@localhost.test';
        $cc = [['email' => 'tester2@localhost.test']];
        $bcc = [['name' => 'Tester3', 'email' => 'tester3@localhost.test']];

        $message = new Email(
            to: [$to],
            subject: $subject,
            content: $content,
            fromName: $fromName,
            fromEmail: $fromEmail,
            cc: $cc,
            bcc: $bcc,
        );

        $response = $sender->send($message);

        $lastEmail = $this->getLastEmail();

        // One To, one Cc and one Bcc: three recipients accepted, which is what
        // the SMTP adapter has always reported and the mock now agrees with.
        $this->assertResponse($response, deliveredTo: 3);
        $this->assertEquals($to, $lastEmail['to'][0]['address']);
        $this->assertEquals($fromEmail, $lastEmail['from'][0]['address']);
        $this->assertEquals($fromName, $lastEmail['from'][0]['name']);
        $this->assertEquals($subject, $lastEmail['subject']);
        $this->assertSame($content, trim((string) $lastEmail['text']));
        $this->assertEquals($cc[0]['email'], $lastEmail['cc'][0]['address']);
        $this->assertEquals($bcc[0]['email'], $lastEmail['envelope']['to'][2]['address']);
    }

    public function testSendEmailWithNamedToRecipient(): void
    {
        $sender = new Mock(host: '127.0.0.1', port: 11025);

        $message = new Email(
            to: [['email' => 'tester@localhost.test', 'name' => 'Test User']],
            subject: 'Named To Test',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
        );

        $response = $sender->send($message);

        $lastEmail = $this->getLastEmail();

        $this->assertResponse($response);
        $this->assertEquals('tester@localhost.test', $lastEmail['to'][0]['address']);
        $this->assertEquals('Test User', $lastEmail['to'][0]['name']);
    }

    public function testSendEmailWithMixedToFormats(): void
    {
        $sender = new Mock(host: '127.0.0.1', port: 11025);

        $message = new Email(
            to: [
                'plain@localhost.test',
                ['email' => 'named@localhost.test', 'name' => 'Named User'],
            ],
            subject: 'Mixed To Test',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
        );

        $response = $sender->send($message);

        $this->assertEquals(2, $response['deliveredTo']);
        $this->assertEquals('success', $response['results'][0]['status']);
        $this->assertEquals('success', $response['results'][1]['status']);

        // Verify both recipients are normalized to array format
        $to = $message->getTo();
        $this->assertEquals('plain@localhost.test', $to[0]['email']);
        $this->assertArrayNotHasKey('name', $to[0]);
        $this->assertEquals('named@localhost.test', $to[1]['email']);
        $this->assertEquals('Named User', $to[1]['name']);
    }

    public function testCcAcceptsPlainStrings(): void
    {
        $message = new Email(
            to: ['tester@localhost.test'],
            subject: 'CC String Test',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
            cc: ['cc@localhost.test'],
        );

        $cc = $message->getCC();
        $this->assertNotNull($cc);
        $this->assertEquals('cc@localhost.test', $cc[0]['email']);
    }

    public function testBccAcceptsPlainStrings(): void
    {
        $message = new Email(
            to: ['tester@localhost.test'],
            subject: 'BCC String Test',
            content: 'Test Content',
            fromName: 'Test Sender',
            fromEmail: 'sender@localhost.test',
            bcc: ['bcc@localhost.test'],
        );

        $bcc = $message->getBCC();
        $this->assertNotNull($bcc);
        $this->assertEquals('bcc@localhost.test', $bcc[0]['email']);
    }

    public function testRejectsEmptyEmailString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Email(
            to: [''],
            subject: 'Test',
            content: 'Test',
            fromName: 'Test',
            fromEmail: 'sender@localhost.test',
        );
    }

    public function testRejectsEmptyEmailInArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Email(
            to: [['email' => '', 'name' => 'Ghost']],
            subject: 'Test',
            content: 'Test',
            fromName: 'Test',
            fromEmail: 'sender@localhost.test',
        );
    }

    public function testRejectsMissingEmailKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Email(
            to: [['name' => 'No Email']],
            subject: 'Test',
            content: 'Test',
            fromName: 'Test',
            fromEmail: 'sender@localhost.test',
        );
    }

    public function testRejectsEmptyEmailInCc(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Email(
            to: ['valid@localhost.test'],
            subject: 'Test',
            content: 'Test',
            fromName: 'Test',
            fromEmail: 'sender@localhost.test',
            cc: [''],
        );
    }
}
