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
     * @return \Iterator<string, array{list<string>, string, bool, int}>
     */
    public static function refusals(): \Iterator
    {
        yield 'credentials refused' => [
            ['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.'],
            'Authentication failed: 535 Authentication failed.',
            true,
            535,
        ];
        yield 'our address refused before any login' => [
            ['554 Too many failed login requests from 167.99.252.167. Try again later. #MS-ST-B'],
            'Expected 220, the server said: 554 Too many failed login requests',
            true,
            554,
        ];
        yield 'our address listed, before and after the HELO fallback' => [
            [
                '220 smtp.improvmx.com ESMTP',
                "551-5.7.1 Your IP is black listed by Spamhaus.org\r\n551 5.7.1 we will ignore all incoming emails from you until the matter is resolved.",
                "551-5.7.1 Your IP is black listed by Spamhaus.org\r\n551 5.7.1 we will ignore all incoming emails from you until the matter is resolved.",
            ],
            'Expected 250, the server said: 551 5.7.1 Your IP is black listed',
            true,
            551,
        ];
        yield 'sender domain not verified' => [
            ['220 smtp.resend.com ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '550 The support.alvey.study domain is not verified. Please, add and verify your domain on https://resend.com/domains'],
            '550 The support.alvey.study domain is not verified.',
            true,
            550,
        ];
        yield 'recipient bounced before' => [
            ['220 smtp.improvmx.com ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '550 5.2.1 Not sending to previously bounced email - ImprovMX v2026.09.24', '250 2.0.0 Ok'],
            '550 5.2.1 Not sending to previously bounced email',
            true,
            550,
        ];
        yield 'sender address refused' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '553 5.7.1 Sender address rejected: not owned by user jane'],
            '553 5.7.1 Sender address rejected',
            true,
            553,
        ];
        yield 'recipient mailbox does not exist' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '550 5.1.1 <to@example.test>: Recipient address rejected: User unknown', '250 2.0.0 Ok'],
            '550 5.1.1',
            true,
            550,
        ];
        yield 'message refused after the data' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '250 2.1.5 Ok', '354 End data with <CR><LF>.<CR><LF>', '554 5.7.1 Message rejected as spam'],
            '554 5.7.1 Message rejected as spam',
            true,
            554,
        ];
        // A 421 like the one smtp.hostinger.com sent in sgp1 on 2026-10-14, here
        // in place of an answer to the login: the server ends the session, and a
        // later attempt can pass.
        yield 'closing at the login' => [
            ['220 smtp.hostinger.com ESMTP', self::EHLO, '421 4.7.0 Too many concurrent SMTP connections, try again later'],
            'Authentication failed: 421 4.7.0 Too many concurrent SMTP connections',
            false,
            421,
        ];
        yield 'sender refused for now' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '451 4.3.0 Temporary lookup failure'],
            '451 4.3.0 Temporary lookup failure',
            false,
            451,
        ];
        yield 'recipient refused for lack of storage' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '452 4.5.3 Insufficient system storage', '250 2.0.0 Ok'],
            '452 4.5.3 Insufficient system storage',
            false,
            452,
        ];
        yield 'closing at the recipient' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '421 4.7.0 Service not available, closing transmission channel'],
            '421 4.7.0 Service not available',
            false,
            421,
        ];
        yield 'message deferred after the data' => [
            ['220 smtp.example.test ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '250 2.1.5 Ok', '354 End data with <CR><LF>.<CR><LF>', '451 4.7.1 Greylisted, please try again in 300 seconds'],
            '451 4.7.1 Greylisted',
            false,
            451,
        ];
        yield 'login server unreachable for now' => [
            ['220 smtp.protonmail.ch ESMTP', self::EHLO, '454 4.7.0 Temporary authentication failure: Connection lost to authentication server'],
            'Authentication failed: 454 4.7.0 Temporary authentication failure',
            false,
            454,
        ];
        yield 'server busy in place of the greeting' => [
            ["421-4.4.5 Server busy, try again later. (smtp.gmail.com)\r\n421 4.4.5  https://support.google.com/a/answer/3221692 - gsmtp"],
            'Expected 220, the server said: 421 4.4.5 Server busy, try again later.',
            false,
            421,
        ];
        yield 'recipient bounced a moment ago' => [
            ['220 smtp.improvmx.com ESMTP', self::EHLO, '235 2.7.0 Authentication successful', '250 2.1.0 Ok', '450 4.0.0 Not sending to temporarily bounced email, please try again later. - ImprovMX v2026.09.29', '250 2.0.0 Ok'],
            '450 4.0.0 Not sending to temporarily bounced email',
            false,
            450,
        ];
        yield 'hung up after the greeting' => [
            ['220 smtp.improvmx.com ESMTP'],
            'The server closed the connection',
            false,
            0,
        ];
        // A 4xx to EHLO is the server busy, not one without ESMTP: it surfaces
        // as itself rather than as a HELO session that offers no login.
        yield 'EHLO refused for now' => [
            ['220 smtp.example.test ESMTP', '450 4.7.0 Temporary EHLO failure'],
            'Expected 250, the server said: 450 4.7.0 Temporary EHLO failure',
            false,
            450,
        ];
        // A 421 ends the session (RFC 5321). The client must not write HELO on
        // the closed socket, which the real transport refuses with a LogicException
        // that no SMTP catch would see.
        yield 'closing at EHLO' => [
            ['220 smtp.gmail.com ESMTP', '421 4.7.0 Try again later, closing connection.'],
            'Expected 250, the server said: 421 4.7.0 Try again later, closing connection.',
            false,
            421,
        ];
    }

    public function testEveryRecipientRefusedKeepsItsOwnVerdict(): void
    {
        $server = new ScriptedSmtpServer([
            '220 smtp.improvmx.com ESMTP',
            self::EHLO,
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '550 5.2.1 Not sending to previously bounced email - ImprovMX v2026.09.24',
            '450 4.0.0 Not sending to temporarily bounced email, please try again later. - ImprovMX v2026.09.29',
            '250 2.0.0 Ok',
        ]);
        $adapter = new SMTP(host: "127.0.0.1:{$server->port}", username: 'jane', password: 'secret', timeout: 2, timelimit: 2);

        $result = $adapter->send(new Email(
            to: [['email' => 'bounced@example.test', 'name' => 'A'], ['email' => 'later@example.test', 'name' => 'B']],
            subject: 'Your code',
            content: '123456',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        ));

        $this->assertSame(0, $result['deliveredTo']);
        $verdicts = \array_column($result['results'], 'permanent', 'recipient');
        $this->assertSame(['bounced@example.test' => true, 'later@example.test' => false], $verdicts);
        $this->assertSame(['bounced@example.test' => 550, 'later@example.test' => 450], \array_column($result['results'], 'code', 'recipient'));
        $errors = \array_column($result['results'], 'error', 'recipient');
        $this->assertStringContainsString('550 5.2.1', (string) $errors['bounced@example.test']);
        $this->assertStringContainsString('450 4.0.0', (string) $errors['later@example.test']);
    }

    public function testAMessageRefusedAsAWholeIsFinalForEveryRecipient(): void
    {
        $server = new ScriptedSmtpServer([
            '220 smtp.resend.com ESMTP',
            self::EHLO,
            '235 2.7.0 Authentication successful',
            '550 The support.alvey.study domain is not verified. Please, add and verify your domain on https://resend.com/domains',
        ]);
        $adapter = new SMTP(host: "127.0.0.1:{$server->port}", username: 'jane', password: 'secret', timeout: 2, timelimit: 2);

        $result = $adapter->send(new Email(
            to: [['email' => 'a@example.test', 'name' => 'A'], ['email' => 'b@example.test', 'name' => 'B']],
            subject: 'Your code',
            content: '123456',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        ));

        $this->assertSame(0, $result['deliveredTo']);
        $this->assertSame([true, true], \array_column($result['results'], 'permanent'));
    }

    /**
     * @param  list<string>  $replies
     */
    #[DataProvider('refusals')]
    public function testAFailedSendSaysWhetherItIsWorthRepeating(array $replies, string $error, bool $permanent, int $code): void
    {
        $server = new ScriptedSmtpServer($replies);

        $result = $this->send("127.0.0.1:{$server->port}");

        $this->assertSame(0, $result['deliveredTo']);
        $this->assertSame('failure', $result['results'][0]['status']);
        $this->assertStringContainsString($error, (string) $result['results'][0]['error']);
        $this->assertSame($permanent, $result['results'][0]['permanent']);
        $this->assertSame($code, $result['results'][0]['code']);
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
        $this->assertSame(0, $result['results'][0]['code']);
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
        $this->assertSame(535, $result['results'][0]['code']);
    }

    public function testOneHostThatMightAnswerNextTimeKeepsTheSendWorthRepeating(): void
    {
        $first = new ScriptedSmtpServer(['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.']);
        $closed = ScriptedSmtpServer::closedPort();

        $result = $this->send("127.0.0.1:{$first->port};127.0.0.1:{$closed}");

        $this->assertStringContainsString('535 Authentication failed.', (string) $result['results'][0]['error']);
        $this->assertFalse($result['results'][0]['permanent']);
    }

    /**
     * Encryption is required and the server never offers it: no retry can change
     * what the server supports.
     */
    public function testAServerWithoutTheRequiredStartTlsIsFinal(): void
    {
        $server = new ScriptedSmtpServer(['220 mail.example.test ESMTP', self::EHLO]);

        $adapter = new SMTP(host: "127.0.0.1:{$server->port}", username: 'jane', password: 'secret', smtpSecure: 'tls', timeout: 2, timelimit: 2);
        $result = $adapter->send(new Email(
            to: [['email' => 'to@example.test', 'name' => 'To']],
            subject: 'Your code',
            content: '123456',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        ));

        $this->assertSame(0, $result['deliveredTo']);
        $this->assertStringContainsString('The server does not offer STARTTLS', (string) $result['results'][0]['error']);
        $this->assertTrue($result['results'][0]['permanent']);
    }

    public function testAHostNobodyListensOnIsWorthRepeating(): void
    {
        $result = $this->send('127.0.0.1:' . ScriptedSmtpServer::closedPort());

        $this->assertStringStartsWith('No SMTP host answered: ', $result['results'][0]['error']);
        $this->assertFalse($result['results'][0]['permanent']);
        // The refused connection's errno is no reply code.
        $this->assertSame(0, $result['results'][0]['code']);
    }

    /**
     * The kernel completes the connection from the listen backlog, and nothing
     * ever greets: the client gives up on the read, as it does on a server too
     * busy to answer.
     */
    public function testAHostThatNeverGreetsIsWorthRepeating(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $address = (string) stream_socket_get_name($server, false);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        try {
            $adapter = new SMTP(host: "127.0.0.1:{$port}", username: 'jane', password: 'secret', timeout: 1, timelimit: 1);
            $result = $adapter->send(new Email(
                to: [['email' => 'to@example.test', 'name' => 'To']],
                subject: 'Your code',
                content: '123456',
                fromName: 'Sender',
                fromEmail: 'sender@example.test',
            ));
        } finally {
            fclose($server);
        }

        $this->assertSame(0, $result['deliveredTo']);
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
