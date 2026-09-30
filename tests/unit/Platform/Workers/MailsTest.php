<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Mails;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Tests\Support\ScriptedSmtpServer;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Registry\Registry;
use Utopia\Telemetry\Adapter\None;

final class SpyMailAdapter extends EmailAdapter
{
    public ?EmailMessage $captured = null;
    public int $deliveredTo = 1;
    public ?string $error = null;
    public bool $emptyResults = false;
    public bool $rejectAsInvalid = false;
    public int $sendCount = 0;

    public function getName(): string
    {
        return 'SpySMTP';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    protected function process(EmailMessage $message): array
    {
        $this->sendCount++;
        $this->captured = $message;

        if ($this->rejectAsInvalid) {
            throw new InvalidArgumentException(InvalidArgumentException::PROVIDER_REJECTED, 'Invalid `to` field.', $message->getTo()[0]['email']);
        }

        $response = [
            'deliveredTo' => $this->deliveredTo,
            'type' => $this->getType(),
            'results' => [],
        ];

        if ($this->emptyResults) {
            if ($this->error !== null) {
                $response['error'] = $this->error;
            }

            return $response;
        }

        $result = [
            'recipient' => $message->getTo()[0]['email'] ?? '',
            'status' => $this->deliveredTo === 0 ? 'failure' : 'success',
        ];

        if ($this->error !== null) {
            $result['error'] = $this->error;
        }

        $response['results'] = [$result];

        return $response;
    }
}

final class MailsTest extends TestCase
{
    private const string EHLO = "250-mail.example.test\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME";

    public function testLegacyMailPayloadIsSentByMailsWorker(): void
    {
        $adapter = new SpyMailAdapter();
        $registry = new Registry();
        $registry->set('smtp', static fn () => new Pool(new Stack(), 'smtp', 1, static fn () => $adapter, 1.0));

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $worker = new Mails();
            $worker->action(
                new Message([
                    'pid' => 'pid',
                    'queue' => 'v1-mails',
                    'timestamp' => \time(),
                    'payload' => [
                        'smtp' => [],
                        'recipient' => 'legacy@example.test',
                        'name' => 'Legacy User',
                        'subject' => 'Hello {{name}}',
                        'body' => 'Body {{name}}',
                        'bodyTemplate' => '',
                        'variables' => ['name' => 'Legacy'],
                        'customMailOptions' => [
                            'senderEmail' => 'sender@example.test',
                            'senderName' => 'Custom Sender',
                            'replyToEmail' => 'reply@example.test',
                            'replyToName' => 'Custom Reply',
                        ],
                    ],
                ]),
                new Document(['$id' => 'project-x']),
                $registry,
                new None(),
            );
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }

        $this->assertSame(1, $adapter->sendCount);
        $this->assertInstanceOf(\Utopia\Messaging\Messages\Email::class, $adapter->captured);

        $message = $adapter->captured;
        $this->assertSame('legacy@example.test', $message->getTo()[0]['email'] ?? '');
        $this->assertSame('Legacy User', $message->getTo()[0]['name'] ?? '');
        $this->assertSame('Hello Legacy', $message->getSubject());
        $this->assertSame('sender@example.test', $message->getFromEmail());
        $this->assertSame('Custom Sender', $message->getFromName());
        $this->assertSame('reply@example.test', $message->getReplyToEmail());
        $this->assertSame('Custom Reply', $message->getReplyToName());
    }

    public function testMailDeliveryFailureIsThrownByMailsWorker(): void
    {
        $adapter = new SpyMailAdapter();
        $adapter->deliveredTo = 0;
        $adapter->error = 'Domain not verified';

        $this->assertMailWorkerThrows($adapter, 'Error sending mail: Domain not verified');
    }

    public function testMailDeliveryFailureUsesTopLevelErrorWhenResultsEmpty(): void
    {
        $adapter = new SpyMailAdapter();
        $adapter->deliveredTo = 0;
        $adapter->emptyResults = true;
        $adapter->error = 'Provider rejected request';

        $this->assertMailWorkerThrows($adapter, 'Error sending mail: Provider rejected request');
    }

    public function testUndeliverableRecipientIsSkippedWithoutASend(): void
    {
        $adapter = new SpyMailAdapter();

        $this->runMailWorker($adapter, recipient: 'john@c.c');

        $this->assertSame(0, $adapter->sendCount);
    }

    public function testProviderRejectionIsSkippedWithoutRetry(): void
    {
        $adapter = new SpyMailAdapter();
        $adapter->rejectAsInvalid = true;

        $this->runMailWorker($adapter, recipient: 'john@example.test');

        $this->assertSame(1, $adapter->sendCount);
    }

    public function testAProjectSmtpThatRefusedTheLoginEndsTheMessageAtTheFirstAttempt(): void
    {
        // The reply MailerSend sent 714 times in a week to one project's stale credentials.
        $server = new ScriptedSmtpServer(['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.']);

        $failure = $this->sendThroughProjectSmtp($server->port);

        $this->assertInstanceOf(PermanentFailure::class, $failure);
        $this->assertSame(401, $failure->getCode());
        $this->assertSame("Error sending mail: No SMTP host answered: 127.0.0.1:{$server->port} (Authentication failed: 535 Authentication failed.)", $failure->getMessage());
        $this->assertContains('AUTH PLAIN ' . \base64_encode("\0jane\0secret"), $server->commands());
    }

    public function testAProjectSmtpThatRefusedTheRecipientEndsTheMessageAtTheFirstAttempt(): void
    {
        $server = new ScriptedSmtpServer([
            '220 smtp.improvmx.com ESMTP',
            self::EHLO,
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '550 5.2.1 Not sending to previously bounced email - ImprovMX v2026.09.24',
            '250 2.0.0 Ok',
        ]);

        $failure = $this->sendThroughProjectSmtp($server->port);

        $this->assertInstanceOf(PermanentFailure::class, $failure);
        $this->assertSame('Error sending mail: 550 5.2.1 Not sending to previously bounced email - ImprovMX v2026.09.24', $failure->getMessage());
    }

    public function testAProjectSmtpThatCannotCheckTheLoginForNowIsRetried(): void
    {
        $server = new ScriptedSmtpServer(['220 smtp.protonmail.ch ESMTP', self::EHLO, '454 4.7.0 Temporary authentication failure: Connection lost to authentication server']);

        $failure = $this->sendThroughProjectSmtp($server->port);

        $this->assertInstanceOf(\Exception::class, $failure);
        $this->assertNotInstanceOf(PermanentFailure::class, $failure);
        $this->assertSame(401, $failure->getCode());
        $this->assertStringContainsString('454 4.7.0 Temporary authentication failure', $failure->getMessage());
    }

    public function testAProjectSmtpNobodyAnswersOnIsRetried(): void
    {
        $failure = $this->sendThroughProjectSmtp(ScriptedSmtpServer::closedPort());

        $this->assertInstanceOf(\Exception::class, $failure);
        $this->assertNotInstanceOf(PermanentFailure::class, $failure);
        $this->assertStringStartsWith('Error sending mail: No SMTP host answered: ', $failure->getMessage());
    }

    public function testAppwritesOwnSmtpRefusingTheLoginIsRetried(): void
    {
        // The same refusal from the provider Appwrite sends through is an
        // incident on our side, not something the project can fix.
        $server = new ScriptedSmtpServer(['220 smtp.mailersend.net ESMTP', self::EHLO, '535 Authentication failed.']);
        $adapter = new SMTP(host: "127.0.0.1:{$server->port}", username: 'jane', password: 'secret', timeout: 2, timelimit: 2);

        $registry = new Registry();
        $registry->set('smtp', static fn () => new Pool(new Stack(), 'smtp', 1, static fn () => $adapter, 1.0));

        $failure = $this->send([], $registry);

        $this->assertInstanceOf(\Exception::class, $failure);
        $this->assertNotInstanceOf(PermanentFailure::class, $failure);
        $this->assertSame(500, $failure->getCode());
        $this->assertStringContainsString('535 Authentication failed.', $failure->getMessage());
        $this->assertContains('AUTH PLAIN ' . \base64_encode("\0jane\0secret"), $server->commands());
    }

    private function sendThroughProjectSmtp(int $port): ?\Throwable
    {
        return $this->send([
            'host' => '127.0.0.1',
            'port' => $port,
            'username' => 'jane',
            'password' => 'secret',
            'secure' => '',
            'senderEmail' => 'sender@example.test',
            'senderName' => 'Sender',
        ], new Registry());
    }

    /**
     * @param array<string, mixed> $smtp
     */
    private function send(array $smtp, Registry $registry): ?\Throwable
    {
        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=smtp.appwrite.test');

        try {
            (new Mails())->action(
                new Message([
                    'pid' => 'pid',
                    'queue' => 'v1-mails',
                    'timestamp' => \time(),
                    'payload' => [
                        'smtp' => $smtp,
                        'recipient' => 'jane@example.test',
                        'name' => 'Jane',
                        'subject' => 'Your code',
                        'body' => '123456',
                        'bodyTemplate' => '',
                        'variables' => [],
                    ],
                ]),
                new Document(['$id' => 'project-x']),
                $registry,
                new None(),
            );
        } catch (\Throwable $failure) {
            return $failure;
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }

        return null;
    }

    private function assertMailWorkerThrows(SpyMailAdapter $adapter, string $expectedMessage): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->runMailWorker($adapter, recipient: 'legacy@example.test');
    }

    private function runMailWorker(SpyMailAdapter $adapter, string $recipient): void
    {
        $registry = new Registry();
        $registry->set('smtp', static fn () => new Pool(new Stack(), 'smtp', 1, static fn () => $adapter, 1.0));

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $worker = new Mails();
            $worker->action(
                new Message([
                    'pid' => 'pid',
                    'queue' => 'v1-mails',
                    'timestamp' => \time(),
                    'payload' => [
                        'smtp' => [],
                        'recipient' => $recipient,
                        'name' => 'Legacy User',
                        'subject' => 'Hello',
                        'body' => 'Body',
                        'bodyTemplate' => '',
                        'variables' => [],
                    ],
                ]),
                new Document(['$id' => 'project-x']),
                $registry,
                new None(),
            );
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }
}
