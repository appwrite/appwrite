<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;

/**
 * Where a host is allowed to be dialled is the caller's rule, handed in as a
 * resolver. These tests are about what a send does with its answer: no host is
 * dialled before it is checked, and the one that is dialled is the address the
 * check returned, not the name it was asked about.
 *
 * Every peer is a real socket on loopback, so a host that is dialled shows up
 * as a session and one that is refused shows up as none.
 */
final class SMTPResolveTest extends TestCase
{
    private const string EHLO = "250-mail.example.test\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME";

    /**
     * @return list<string>
     */
    private static function delivery(): array
    {
        return [
            '220 smtp.example.test ESMTP',
            self::EHLO,
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '250 2.1.5 Ok',
            '354 End data with <CR><LF>.<CR><LF>',
            '250 2.0.0 Ok: queued as 4ZbX1',
        ];
    }

    /**
     * @return array{deliveredTo: int, results: list<array<string, mixed>>}
     */
    private function send(string $host, \Closure $resolve): array
    {
        $adapter = new SMTP(host: $host, username: 'jane', password: 'secret', timeout: 2, timelimit: 2, resolve: $resolve);

        /** @var array{deliveredTo: int, results: list<array<string, mixed>>} */
        return $adapter->send(new Email(
            to: [['email' => 'to@example.test', 'name' => 'To']],
            subject: 'Your code',
            content: '123456',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        ));
    }

    public function testARefusedHostIsNeverDialled(): void
    {
        // Something answers on this port, so a dial would show as a session
        $server = new ScriptedSmtpServer(self::delivery());
        $asked = [];

        $result = $this->send("127.0.0.1:{$server->port}", function (string $host) use (&$asked): string {
            $asked[] = $host;

            throw new \InvalidArgumentException("Address {$host} is in a private or reserved range.");
        });

        $this->assertSame(['127.0.0.1'], $asked);
        $this->assertSame(0, $result['deliveredTo']);
        $this->assertSame('failure', $result['results'][0]['status']);
        $this->assertStringContainsString('is in a private or reserved range', (string) $result['results'][0]['error']);
        $this->assertStringNotContainsString('Cannot reach', (string) $result['results'][0]['error']);
        // Nothing about a refused host changes between attempts
        $this->assertTrue($result['results'][0]['permanent']);
    }

    public function testAnAddressLiteralIsCheckedLikeAName(): void
    {
        $asked = [];

        $result = $this->send('169.254.169.254', function (string $host) use (&$asked): string {
            $asked[] = $host;

            throw new \InvalidArgumentException("Address {$host} is in a private or reserved range.");
        });

        $this->assertSame(['169.254.169.254'], $asked);
        $this->assertSame('failure', $result['results'][0]['status']);
        $this->assertStringContainsString('169.254.169.254:25 (Address 169.254.169.254 is in a private or reserved range.)', (string) $result['results'][0]['error']);
    }

    public function testEveryEntryOfAHostListIsCheckedByName(): void
    {
        $asked = [];

        $result = $this->send('tls://smtp1.example.com:587;smtp2.example.com', function (string $host) use (&$asked): string {
            $asked[] = $host;

            throw new \InvalidArgumentException("Refused {$host}.");
        });

        // The scheme and the port are not part of the name the rule is asked about
        $this->assertSame(['smtp1.example.com', 'smtp2.example.com'], $asked);
        $this->assertSame(0, $result['deliveredTo']);
        $this->assertStringContainsString('smtp1.example.com:587 (Refused smtp1.example.com.)', (string) $result['results'][0]['error']);
        $this->assertStringContainsString('smtp2.example.com:25 (Refused smtp2.example.com.)', (string) $result['results'][0]['error']);
    }

    public function testARefusedEntryDoesNotStopTheNextOne(): void
    {
        $server = new ScriptedSmtpServer(self::delivery());

        $result = $this->send(
            "internal.example.com:{$server->port};smtp.example.com:{$server->port}",
            fn (string $host): string => match ($host) {
                'smtp.example.com' => '127.0.0.1',
                default => throw new \InvalidArgumentException("Refused {$host}."),
            },
        );

        $this->assertSame(1, $result['deliveredTo']);
        $this->assertSame('success', $result['results'][0]['status']);
    }

    public function testTheResolvedAddressIsDialledNotTheName(): void
    {
        $server = new ScriptedSmtpServer(self::delivery());

        // A name that resolves nowhere: reaching the server can only come from the resolver
        $result = $this->send("no-such-host.invalid:{$server->port}", fn (string $host): string => '127.0.0.1');

        $this->assertSame(1, $result['deliveredTo']);
        $this->assertSame('success', $result['results'][0]['status']);
    }
}
