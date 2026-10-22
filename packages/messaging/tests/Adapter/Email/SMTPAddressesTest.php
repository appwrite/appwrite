<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;

/**
 * A host given an address is dialled there, not wherever its name resolves.
 */
final class SMTPAddressesTest extends TestCase
{
    private const array ACCEPTED = [
        '220 smtp.example.test ESMTP',
        "250-smtp.example.test\r\n250 8BITMIME",
        '250 2.1.0 Ok',
        '250 2.1.5 Ok',
        '354 End data with <CR><LF>.<CR><LF>',
        '250 2.0.0 Ok',
        '221 2.0.0 Bye',
    ];

    public function testAHostWithAnAddressIsDialledOnIt(): void
    {
        $server = new ScriptedSmtpServer(self::ACCEPTED);
        $adapter = new SMTP(
            host: "smtp.example.invalid:{$server->port}",
            timeout: 2,
            timelimit: 2,
            addresses: ['smtp.example.invalid' => '127.0.0.1'],
        );

        $result = $adapter->send($this->email());

        $this->assertSame(1, $result['deliveredTo']);
    }

    public function testAHostWithoutAnAddressIsDialledByName(): void
    {
        $server = new ScriptedSmtpServer(self::ACCEPTED);
        $adapter = new SMTP(
            host: "smtp.example.invalid:{$server->port}",
            timeout: 2,
            timelimit: 2,
            addresses: ['other.example.invalid' => '127.0.0.1'],
        );

        $result = $adapter->send($this->email());

        $this->assertSame(0, $result['deliveredTo']);
        $this->assertStringContainsString('smtp.example.invalid', (string) $result['results'][0]['error']);
    }

    private function email(): Email
    {
        return new Email(
            to: [['email' => 'to@example.test', 'name' => 'To']],
            subject: 'Subject',
            content: 'Content',
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
        );
    }
}
