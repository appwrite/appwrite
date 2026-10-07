<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Mails;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
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

    /**
     * A project's own server that cannot be reached may answer the next attempt,
     * so the job stays retryable; Appwrite's provider is never used in its place.
     */
    public function testAnUnreachableProjectSmtpIsRetried(): void
    {
        $adapter = new SpyMailAdapter();

        try {
            $this->runMailWorker($adapter, recipient: 'john@example.test', smtp: [
                'host' => '127.0.0.1',
                'port' => 1,
                'senderEmail' => 'sender@example.test',
            ]);
            $this->fail('An unreachable project SMTP server must fail the job so it is retried');
        } catch (PermanentFailure) {
            $this->fail('An unreachable server may answer next time; the job must not end');
        } catch (\Exception $error) {
            $this->assertStringContainsString('No SMTP host answered', $error->getMessage());
        }

        $this->assertSame(0, $adapter->sendCount);
    }

    /**
     * A project's own server that refused for good answers every attempt the same
     * way, so the job ends instead of repeating the login from the shared egress IP.
     */
    public function testAProjectSmtpThatRefusesForGoodEndsTheJob(): void
    {
        $adapter = new SpyMailAdapter();
        $server = new ScriptedSmtpServer([
            '220 smtp.example.test ESMTP',
            "250-smtp.example.test\r\n250 AUTH PLAIN LOGIN",
            '535 5.7.8 Authentication credentials invalid',
        ]);

        try {
            $this->runMailWorker($adapter, recipient: 'john@example.test', smtp: [
                'host' => '127.0.0.1',
                'port' => $server->port,
                'username' => 'jane',
                'password' => 'wrong',
                'senderEmail' => 'sender@example.test',
            ]);
            $this->fail('A permanent refusal must end the job');
        } catch (PermanentFailure $failure) {
            $this->assertStringContainsString('535', $failure->getMessage());
        }

        $this->assertSame(0, $adapter->sendCount);
    }

    private function assertMailWorkerThrows(SpyMailAdapter $adapter, string $expectedMessage): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->runMailWorker($adapter, recipient: 'legacy@example.test');
    }

    /**
     * @param array<string, mixed> $smtp
     */
    private function runMailWorker(SpyMailAdapter $adapter, string $recipient, array $smtp = []): void
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
                        'smtp' => $smtp,
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
