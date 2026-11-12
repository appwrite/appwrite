<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;

/**
 * A kept session the server closed while it sat idle fails its next command.
 * The message goes out once more on a new connection, unless it may already
 * have been taken.
 *
 * Each test offers a connection after the one under test, so a send that is
 * not retried is one that leaves it unused.
 */
final class SMTPKeepAliveTest extends TestCase
{
    private const array DELIVERED = [
        '220 smtp.example.test ESMTP',
        '250 smtp.example.test',
        '250 2.1.0 Ok',
        '250 2.1.5 Ok',
        '354 End data with <CR><LF>.<CR><LF>',
        '250 2.0.0 Ok: queued as 4ZbX1',
    ];

    public function testA421OnAKeptSessionIsSentAgain(): void
    {
        $server = new ScriptedSmtpServer(
            [...self::DELIVERED, '421 4.4.2 Idle timeout, closing connection'],
            self::DELIVERED,
        );
        $adapter = $this->adapter($server);
        $this->send($adapter);

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);
    }

    public function testAKeptSessionTheServerHungUpIsSentAgain(): void
    {
        $server = new ScriptedSmtpServer(self::DELIVERED, self::DELIVERED);
        $adapter = $this->adapter($server);
        $this->send($adapter);

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);
    }

    public function testAMessageWhoseReplyWasLostIsNotSentAgain(): void
    {
        $server = new ScriptedSmtpServer(
            [...self::DELIVERED, '250 2.1.0 Ok', '250 2.1.5 Ok', '354 End data with <CR><LF>.<CR><LF>'],
            self::DELIVERED,
        );
        $adapter = $this->adapter($server);
        $this->send($adapter);

        $this->assertSame(0, $this->send($adapter)['deliveredTo']);
    }

    private function adapter(ScriptedSmtpServer $server): SMTP
    {
        return new SMTP(host: "127.0.0.1:{$server->port}", timeout: 2, keepAlive: true, timelimit: 2, pingThreshold: 60);
    }

    /**
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    private function send(SMTP $adapter): array
    {
        return $adapter->send(new Email(
            to: [['email' => 'to@example.test', 'name' => 'To']],
            subject: 'Your code',
            content: '123456',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        ));
    }
}
