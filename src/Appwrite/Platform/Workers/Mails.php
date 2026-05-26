<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Template\Template;
use Exception;
use Swoole\Runtime;
use Throwable;
use Utopia\Database\Document;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

class Mails extends Action
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
        return 'mails';
    }

    public function __construct()
    {
        $this
            ->desc('Mails worker')
            ->inject('message')
            ->inject('project')
            ->inject('register')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(Message $message, Document $project, Registry $register, Log $log): void
    {
        if (\class_exists(Runtime::class)) {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        }

        $payload = $message->getPayload();
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $smtp = $payload['smtp'] ?? [];
        if (!\is_array($smtp)) {
            $smtp = [];
        }

        if (empty($smtp) && empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('Skipped mail processing. No SMTP configuration has been set.');
        }

        $type = empty($smtp) ? 'cloud' : 'smtp';
        $log->addTag('type', $type);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', 'localhost'));

        $recipient = (string) ($payload['recipient'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $variables = $payload['variables'] ?? [];
        if (!\is_array($variables)) {
            $variables = [];
        }
        $variables['host'] = $protocol . '://' . $hostname;
        $name = (string) ($payload['name'] ?? '');
        $body = (string) ($payload['body'] ?? '');
        $preview = (string) ($payload['preview'] ?? '');

        $variables['subject'] = $subject;
        $variables['heading'] = $variables['heading'] ?? $subject;
        $variables['year'] = \date('Y');

        $attachment = $payload['attachment'] ?? [];
        if (!\is_array($attachment)) {
            $attachment = [];
        }

        $bodyTemplate = (string) ($payload['bodyTemplate'] ?? '');
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
        if (!\is_array($customMailOptions)) {
            $customMailOptions = [];
        }

        if (!empty($customMailOptions['senderEmail'])) {
            $fromEmail = (string) $customMailOptions['senderEmail'];
        }
        if (!empty($customMailOptions['senderName'])) {
            $fromName = (string) $customMailOptions['senderName'];
        }

        if (!empty($customMailOptions['replyToEmail']) || !empty($customMailOptions['replyToName'])) {
            $replyTo = (string) ($customMailOptions['replyToEmail'] ?? $replyTo);
            $replyToName = (string) ($customMailOptions['replyToName'] ?? $replyToName);
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
            to: [['email' => $recipient, 'name' => $name]],
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
        } catch (Throwable $error) {
            if ($type === 'smtp') {
                throw new Exception('Error sending mail: ' . $error->getMessage(), 401);
            }
            throw new Exception('Error sending mail: ' . $error->getMessage(), 500);
        }
    }
}
