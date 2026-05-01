<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Template\Template;
use Appwrite\Utopia\Messaging\Adapter\Console as ConsoleAdapter;
use Appwrite\Utopia\Messaging\Adapter\Webhook as WebhookAdapter;
use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use Appwrite\Utopia\Messaging\Messages\Webhook as WebhookMessage;
use Exception;
use Swoole\Runtime;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

class Notifications extends Action
{
    protected int $previewMaxLen = 150;
    protected string $whitespaceCodes = '&#xa0;&#x200C;&#x200B;&#x200D;&#x200E;&#x200F;&#xFEFF;';

    /**
     * @var array<string, string>
     */
    protected array $richTextParams = [
        'b' => '<strong>',
        '/b' => '</strong>',
    ];

    public static function getName(): string
    {
        return 'notifications';
    }

    public function __construct()
    {
        $this
            ->desc('Notifications worker')
            ->inject('message')
            ->inject('project')
            ->inject('register')
            ->inject('dbForProject')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(Message $message, Document $project, Registry $register, Database $dbForProject, Log $log): void
    {
        if (\class_exists(Runtime::class)) {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        }
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $deduplicationKey = $payload['deduplicationKey'] ?? '';
        $messageId = $deduplicationKey !== '' ? \md5($deduplicationKey) : '';

        if ($messageId !== '' && $this->alreadyDelivered($dbForProject, $messageId)) {
            $log->addTag('dedup', 'hit');
            return;
        }

        $recipients = $this->resolveRecipients($payload);
        if (empty($recipients)) {
            throw new Exception('No recipients in payload');
        }

        foreach ($recipients as $recipient) {
            $channel = $recipient['channel'];
            try {
                $this->dispatch($channel, $recipient['address'], $payload, $register, $dbForProject, $log);
                if ($messageId !== '') {
                    $this->persistAlert($dbForProject, $messageId, $channel, $recipient['address'], $payload);
                }
            } catch (Throwable $error) {
                $log->addTag('channel', $channel);
                $log->addTag('error', $error->getMessage());
                throw $error;
            }
        }
    }

    /**
     * @return array<int, array{address: string, channel: string}>
     */
    private function resolveRecipients(array $payload): array
    {
        $recipients = $payload['recipients'] ?? [];
        if (!empty($recipients)) {
            return $recipients;
        }

        $address = $payload['recipient'] ?? '';
        if ($address === '') {
            return [];
        }

        return [['address' => $address, 'channel' => NOTIFICATION_CHANNEL_EMAIL]];
    }

    private function alreadyDelivered(Database $database, string $messageId): bool
    {
        try {
            $existing = $database->getDocument('alerts', $messageId);
            return !$existing->isEmpty();
        } catch (Throwable) {
            return false;
        }
    }

    protected function dispatch(string $channel, string $address, array $payload, Registry $register, Database $database, Log $log): void
    {
        switch ($channel) {
            case NOTIFICATION_CHANNEL_EMAIL:
                $this->dispatchEmail($address, $payload, $register, $log);
                return;
            case NOTIFICATION_CHANNEL_CONSOLE:
                $this->dispatchConsole($address, $payload, $database);
                return;
            case NOTIFICATION_CHANNEL_WEBHOOK:
                $this->dispatchWebhook($address, $payload);
                return;
            default:
                throw new Exception('Unsupported notification channel: ' . $channel);
        }
    }

    protected function dispatchEmail(string $address, array $payload, Registry $register, Log $log): void
    {
        $smtp = $payload['smtp'] ?? [];
        if (empty($smtp) && empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('Skipped email notification. No SMTP configuration has been set.');
        }

        $type = empty($smtp) ? 'cloud' : 'smtp';
        $log->addTag('type', $type);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_CONSOLE_DOMAIN');

        $subject = $payload['subject'] ?? '';
        $variables = $payload['variables'] ?? [];
        $variables = \array_merge($variables, $payload['templateParams'] ?? []);
        $variables['host'] = $protocol . '://' . $hostname;
        $name = $payload['name'] ?? '';
        $body = $payload['body'] ?? '';
        $preview = $payload['preview'] ?? '';

        $variables['subject'] = $subject;
        $variables['heading'] = $variables['heading'] ?? $subject;
        $variables['year'] = date('Y');

        $attachment = $payload['attachment'] ?? [];
        $bodyTemplate = $payload['bodyTemplate'] ?? '';
        if (empty($bodyTemplate)) {
            $bodyTemplate = $payload['template'] ?? '';
        }
        if (empty($bodyTemplate)) {
            $bodyTemplate = __DIR__ . '/../../../../app/config/locale/templates/email-base.tpl';
        }

        $bodyTemplate = Template::fromFile($bodyTemplate);
        $bodyTemplate->setParam('{{body}}', $body, escapeHtml: false);
        foreach ($variables as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: $key !== 'redirect');
        }
        foreach ($this->richTextParams as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: false);
        }

        $previewWhitespace = '';
        if (!empty($preview)) {
            $previewTemplate = Template::fromString($preview);
            foreach ($variables as $key => $value) {
                $previewTemplate->setParam('{{' . $key . '}}', $value);
            }
            $preview = \strip_tags($previewTemplate->render());

            $previewLen = \strlen($preview);
            if ($previewLen < $this->previewMaxLen) {
                $previewWhitespace = \str_repeat($this->whitespaceCodes, $this->previewMaxLen - $previewLen);
            }
        }

        $bodyTemplate->setParam('{{preview}}', $preview);
        $bodyTemplate->setParam('{{previewWhitespace}}', $previewWhitespace, false);

        $body = $bodyTemplate->render();

        $subjectTemplate = Template::fromString($subject);
        foreach ($variables as $key => $value) {
            $subjectTemplate->setParam('{{' . $key . '}}', $value);
        }
        $subject = \strip_tags($subjectTemplate->render());

        /** @var EmailAdapter $adapter */
        $adapter = empty($smtp)
            ? $register->get('smtp')
            : new SMTP(
                host: $smtp['host'],
                port: (int) $smtp['port'],
                username: $smtp['username'] ?? '',
                password: $smtp['password'] ?? '',
                smtpSecure: $smtp['secure'] ?? '',
                smtpAutoTLS: false,
                xMailer: 'Appwrite Mailer',
                timeout: 10,
                keepAlive: true,
                timelimit: 30,
            );

        $defaultFromEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $defaultFromName = \urldecode(System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));

        $fromEmail = !empty($smtp) ? ($smtp['senderEmail'] ?? $defaultFromEmail) : $defaultFromEmail;
        $fromName = !empty($smtp) ? ($smtp['senderName'] ?? $defaultFromName) : $defaultFromName;
        $replyTo = $defaultFromEmail;
        $replyToName = $defaultFromName;

        $customMailOptions = $payload['customMailOptions'] ?? [];

        if (!empty($customMailOptions['senderEmail'])) {
            $fromEmail = $customMailOptions['senderEmail'];
        }
        if (!empty($customMailOptions['senderName'])) {
            $fromName = $customMailOptions['senderName'];
        }

        if (!empty($customMailOptions['replyToEmail']) || !empty($customMailOptions['replyToName'])) {
            $replyTo = $customMailOptions['replyToEmail'] ?? $replyTo;
            $replyToName = $customMailOptions['replyToName'] ?? $replyToName;
        } elseif (!empty($smtp)) {
            $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
            $replyTo = !empty($smtpReplyToEmail) ? $smtpReplyToEmail : ($smtp['senderEmail'] ?? $replyTo);
            $replyToName = !empty($smtp['replyToName']) ? $smtp['replyToName'] : ($smtp['senderName'] ?? $replyToName);
        }

        $attachments = null;
        if (!empty($attachment['content'] ?? '')) {
            $attachments = [
                new Attachment(
                    name: $attachment['filename'] ?? 'unknown.file',
                    path: '',
                    type: $attachment['type'] ?? 'plain/text',
                    content: \base64_decode($attachment['content']),
                ),
            ];
        }

        $emailMessage = new EmailMessage(
            to: [['email' => $address, 'name' => $name]],
            subject: $subject,
            content: $body,
            fromName: $fromName,
            fromEmail: $fromEmail,
            replyToName: $replyToName,
            replyToEmail: $replyTo,
            attachments: $attachments,
            html: true,
        );

        try {
            $adapter->send($emailMessage);
        } catch (\Throwable $error) {
            if ($type === 'smtp') {
                throw new Exception('Error sending notification: ' . $error->getMessage(), 401);
            }
            throw new Exception('Error sending notification: ' . $error->getMessage(), 500);
        }
    }

    protected function dispatchConsole(string $address, array $payload, Database $database): void
    {
        $project = $payload['project'] ?? null;
        $projectId = \is_array($project) ? ($project['$id'] ?? null) : null;

        $title = $payload['subject'] ?? '';
        $body = $payload['body'] ?? '';
        $params = $payload['templateParams'] ?? ($payload['variables'] ?? []);
        if ($title !== '' && !empty($params)) {
            $rendered = Template::fromString($title);
            foreach ($params as $key => $value) {
                $rendered->setParam('{{' . $key . '}}', (string) $value);
            }
            $title = \strip_tags($rendered->render());
        }

        $recipients = [['userId' => $address]];

        $deduplicationKey = $payload['deduplicationKey'] ?? '';
        $messageId = $deduplicationKey !== '' ? \md5($deduplicationKey) : null;

        $consoleMessage = new ConsoleMessage(
            recipients: $recipients,
            title: $title,
            body: $body,
            type: 'info',
            messageId: $messageId,
            projectId: $projectId,
        );

        $adapter = new ConsoleAdapter($database);
        $adapter->send($consoleMessage);
    }

    protected function dispatchWebhook(string $address, array $payload): void
    {
        $body = [
            'subject' => $payload['subject'] ?? '',
            'body' => $payload['body'] ?? '',
            'template' => $payload['template'] ?? '',
            'params' => $payload['templateParams'] ?? [],
            'project' => \is_array($payload['project'] ?? null) ? ($payload['project']['$id'] ?? null) : null,
            'deduplicationKey' => $payload['deduplicationKey'] ?? '',
            'events' => $payload['events'] ?? [],
        ];

        $secret = System::getEnv('_APP_NOTIFICATIONS_WEBHOOK_SECRET', '') ?: null;

        $message = new WebhookMessage(
            urls: [$address],
            payload: $body,
            signingSecret: $secret,
        );

        $adapter = new WebhookAdapter();
        $result = $adapter->send($message);

        if (($result['deliveredTo'] ?? 0) === 0) {
            $error = $result['results'][0]['error'] ?? 'Unknown error';
            throw new Exception('Webhook delivery failed: ' . $error);
        }
    }

    private function persistAlert(Database $database, string $messageId, string $channel, string $address, array $payload): void
    {
        $project = $payload['project'] ?? null;
        $projectId = \is_array($project) ? ($project['$id'] ?? null) : null;
        $permissions = $payload['permissions'] ?? [];

        $document = new Document([
            '$id' => $messageId . '_' . \substr(\md5($channel . $address), 0, 8),
            '$permissions' => $permissions,
            'messageId' => $messageId,
            'type' => 'info',
            'channel' => $channel,
            'userId' => $channel === NOTIFICATION_CHANNEL_CONSOLE ? $address : null,
            'projectId' => $projectId,
            'title' => $payload['subject'] ?? '',
            'body' => $payload['body'] ?? '',
        ]);

        try {
            $database->createDocument('alerts', $document);
        } catch (DuplicateException) {
            // Idempotent — duplicate persistence is fine
        }
    }
}
