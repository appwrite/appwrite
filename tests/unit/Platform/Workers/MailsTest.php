<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Mails;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\Telemetry\Adapter\None;

final class SpyMailAdapter extends EmailAdapter
{
    public ?EmailMessage $captured = null;
    public int $deliveredTo = 1;
    public ?string $error = null;
    public bool $emptyResults = false;
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
        $registry->set('smtp', static fn () => $adapter);

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
                new Log(),
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

    private function assertMailWorkerThrows(SpyMailAdapter $adapter, string $expectedMessage): void
    {
        $registry = new Registry();
        $registry->set('smtp', static fn () => $adapter);

        $previousSmtpHost = \getenv('_APP_SMTP_HOST');
        \putenv('_APP_SMTP_HOST=spy.smtp.test');

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expectedMessage);

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
                        'subject' => 'Hello',
                        'body' => 'Body',
                        'bodyTemplate' => '',
                        'variables' => [],
                    ],
                ]),
                new Document(['$id' => 'project-x']),
                $registry,
                new Log(),
                new None(),
            );
        } finally {
            \putenv($previousSmtpHost === false ? '_APP_SMTP_HOST' : '_APP_SMTP_HOST=' . $previousSmtpHost);
        }
    }
}
