<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;

/**
 * Whether a failed send says that sending it again is pointless.
 *
 * Every reply below is one a production server sent, taken from the mails
 * worker's own error log, and each is spoken by a real socket, so the adapter
 * reads them through the same client it uses in production.
 */
final class SMTPRefusalTest extends TestCase
{
    private const string EHLO = "250-mail.example.test\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME";

    /**
     * @return array<string, array{list<string>, string, bool}>
     */
    public static function refusals(): array
    {
        return [
            'credentials refused' => [
                ['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.'],
                'Authentication failed: 535 Authentication failed.',
                true,
            ],
            'our address refused before any login' => [
                ['554 Too many failed login requests from 167.99.252.167. Try again later. #MS-ST-B'],
                'Expected 220, the server said: 554 Too many failed login requests',
                true,
            ],
            'our address not on the account allow list' => [
                ['220 smtp-relay.brevo.com ESMTP', self::EHLO, '525 5.7.1 Unauthorized IP address'],
                'Authentication failed: 525 5.7.1 Unauthorized IP address',
                true,
            ],
            'our address listed, before and after the HELO fallback' => [
                [
                    '220 smtp.improvmx.com ESMTP',
                    "551-5.7.1 Your IP is black listed by Spamhaus.org\r\n551 5.7.1 we will ignore all incoming emails from you until the matter is resolved.",
                    "551-5.7.1 Your IP is black listed by Spamhaus.org\r\n551 5.7.1 we will ignore all incoming emails from you until the matter is resolved.",
                ],
                'Expected 250, the server said: 551 5.7.1 Your IP is black listed',
                true,
            ],
            'sender domain not verified' => [
                ['220 smtp.resend.com ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '550 The support.alvey.study domain is not verified. Please, add and verify your domain on https://resend.com/domains'],
                '550 The support.alvey.study domain is not verified.',
                true,
            ],
            'recipient bounced before' => [
                ['220 smtp.improvmx.com ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '550 5.2.1 Not sending to previously bounced email - ImprovMX v2026.09.24', '250 2.0.0 Ok'],
                '550 5.2.1 Not sending to previously bounced email',
                true,
            ],
            'login server unreachable for now' => [
                ['220 smtp.protonmail.ch ESMTP', self::EHLO, '454 4.7.0 Temporary authentication failure: Connection lost to authentication server'],
                'Authentication failed: 454 4.7.0 Temporary authentication failure',
                false,
            ],
            'server busy in place of the greeting' => [
                ["421-4.4.5 Server busy, try again later. (smtp.gmail.com)\r\n421 4.4.5  https://support.google.com/a/answer/3221692 - gsmtp"],
                'Expected 220, the server said: 421 4.4.5 Server busy, try again later.',
                false,
            ],
            'recipient bounced a moment ago' => [
                ['220 smtp.improvmx.com ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '450 4.0.0 Not sending to temporarily bounced email, please try again later. - ImprovMX v2026.09.29', '250 2.0.0 Ok'],
                '450 4.0.0 Not sending to temporarily bounced email',
                false,
            ],
            'hung up after the greeting' => [
                ['220 smtp.improvmx.com ESMTP'],
                'The server closed the connection',
                false,
            ],
        ];
    }

    /**
     * @param  list<string>  $replies
     */
    #[DataProvider('refusals')]
    public function testAFailedSendSaysWhetherItIsWorthRepeating(array $replies, string $error, bool $permanent): void
    {
        $server = new ScriptedSmtpServer($replies);

        $result = $this->send("127.0.0.1:{$server->port}");

        $this->assertSame(0, $result['deliveredTo']);
        $this->assertSame('failure', $result['results'][0]['status']);
        $this->assertStringContainsString($error, $result['results'][0]['error']);
        $this->assertSame($permanent, $result['results'][0]['permanent']);
    }

    public function testADeliveredMessageIsNotAFailureOfAnyKind(): void
    {
        $server = new ScriptedSmtpServer([
            '220 smtp.example.test ESMTP',
            self::EHLO,
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '250 2.1.5 Ok',
            '354 End data with <CR><LF>.<CR><LF>',
            '250 2.0.0 Ok: queued as 4ZbX1',
        ]);

        $result = $this->send("127.0.0.1:{$server->port}");

        $this->assertSame(1, $result['deliveredTo']);
        $this->assertSame('success', $result['results'][0]['status']);
        $this->assertFalse($result['results'][0]['permanent']);
    }

    public function testTheRefusedLoginIsTriedOnceAndNotAgainWithinTheSend(): void
    {
        $server = new ScriptedSmtpServer(['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.']);

        $this->send("127.0.0.1:{$server->port}");

        $commands = $server->commands();
        $this->assertCount(1, array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'AUTH ')));
    }

    public function testEveryHostRefusingForGoodIsPermanent(): void
    {
        $first = new ScriptedSmtpServer(['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.']);
        $second = new ScriptedSmtpServer(['554 Too many failed login requests from 167.99.252.167. Try again later. #MS-ST-B']);

        $result = $this->send("127.0.0.1:{$first->port};127.0.0.1:{$second->port}");

        $this->assertStringStartsWith('No SMTP host answered: ', $result['results'][0]['error']);
        $this->assertTrue($result['results'][0]['permanent']);
    }

    public function testOneHostThatMightAnswerNextTimeKeepsTheSendWorthRepeating(): void
    {
        $first = new ScriptedSmtpServer(['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.']);
        $closed = ScriptedSmtpServer::closedPort();

        $result = $this->send("127.0.0.1:{$first->port};127.0.0.1:{$closed}");

        $this->assertStringContainsString('535 Authentication failed.', $result['results'][0]['error']);
        $this->assertFalse($result['results'][0]['permanent']);
    }

    public function testAHostNobodyListensOnIsWorthRepeating(): void
    {
        $result = $this->send('127.0.0.1:' . ScriptedSmtpServer::closedPort());

        $this->assertStringStartsWith('No SMTP host answered: ', $result['results'][0]['error']);
        $this->assertFalse($result['results'][0]['permanent']);
    }

    /**
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    private function send(string $host): array
    {
        $adapter = new SMTP(host: $host, username: 'jane', password: 'secret', timeout: 2, timelimit: 2);

        return $adapter->send(new Email(
            to: [['email' => 'to@example.test', 'name' => 'To']],
            subject: 'Your code',
            content: '123456',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        ));
    }
}
