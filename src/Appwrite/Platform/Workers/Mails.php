<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Template\Template;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Runtime;
use Utopia\Database\Document;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment as EmailAttachment;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

class Mails extends Action
{
    protected int $previewMaxLen = 150;

    protected string $whitespaceCodes = '&#xa0;&#x200C;&#x200B;&#x200D;&#x200E;&#x200F;&#xFEFF;';


    public static function getName(): string
    {
        return 'mails';
    }

    /**
     * @throws Exception
     */
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

    /**
     * @var array<string, string>
     */
    protected array $richTextParams = [
        'b' => '<strong>',
        '/b' => '</strong>',
    ];

    /**
     * @param Message $message
     * @param Registry $register
     * @param Log $log
     * @throws \PHPMailer\PHPMailer\Exception
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Document $project, Registry $register, Log $log): void
    {
        Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $smtp = $payload['smtp'];

        if (empty($smtp) && empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('Skipped mail processing. No SMTP configuration has been set.');
        }

        $type = empty($smtp) ? 'cloud' : 'smtp';
        $log->addTag('type', $type);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_CONSOLE_DOMAIN');

        $recipient = $payload['recipient'];
        $subject = $payload['subject'];
        $variables = $payload['variables'];
        $variables['host'] = $protocol . '://' . $hostname;
        $name = $payload['name'];
        $body = $payload['body'];
        $preview = $payload['preview'] ?? '';

        $variables['subject'] = $subject;
        $variables['heading'] = $variables['heading'] ?? $subject;
        $variables['year'] = date("Y");

        $attachment = $payload['attachment'] ?? [];
        $bodyTemplate = $payload['bodyTemplate'];
        if (empty($bodyTemplate)) {
            $bodyTemplate = __DIR__ . '/../../../../app/config/locale/templates/email-base.tpl';
        }
        $bodyTemplate = Template::fromFile($bodyTemplate);
        $bodyTemplate->setParam('{{body}}', $body, escapeHtml: false);
        foreach ($variables as $key => $value) {
            // TODO: hotfix for redirect param
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
            // render() will return the subject in <p> tags, so use strip_tags() to remove them
            $preview = \strip_tags($previewTemplate->render());

            $previewLen = strlen($preview);
            if ($previewLen < $this->previewMaxLen) {
                $previewWhitespace =  str_repeat($this->whitespaceCodes, $this->previewMaxLen - $previewLen);
            }
        }


        $bodyTemplate->setParam('{{preview}}', $preview);
        $bodyTemplate->setParam('{{previewWhitespace}}', $previewWhitespace, false);

        $body = $bodyTemplate->render();

        $subjectTemplate = Template::fromString($subject);
        foreach ($variables as $key => $value) {
            $subjectTemplate->setParam('{{' . $key . '}}', $value);
        }
        // render() will return the subject in <p> tags, so use strip_tags() to remove them
        $subject = \strip_tags($subjectTemplate->render());

        $transport = empty($smtp)
            ? $register->get('smtp')
            : $this->getMailer($smtp);
        $customMailOptions = $payload['customMailOptions'] ?? [];
        $mailOptions = $this->resolveMailOptions(
            $smtp,
            $customMailOptions,
            $transport instanceof PHPMailer ? ($transport->From ?: null) : null,
            $transport instanceof PHPMailer ? ($transport->FromName ?: null) : null
        );

        try {
            match (true) {
                $transport instanceof PHPMailer => $this->sendWithMailer(
                    $transport,
                    $recipient,
                    $name,
                    $subject,
                    $body,
                    $attachment,
                    $mailOptions
                ),
                $transport instanceof EmailAdapter => $this->sendWithAdapter(
                    $transport,
                    $recipient,
                    $name,
                    $subject,
                    $body,
                    $attachment,
                    $mailOptions
                ),
                default => throw new Exception('SMTP resource must resolve to PHPMailer or a Utopia email adapter'),
            };
        } catch (\Throwable $error) {
            if ($type === 'smtp') {
                throw new Exception('Error sending mail: ' . $error->getMessage(), 401);
            }
            throw new Exception('Error sending mail: ' . $error->getMessage(), 500);
        }
    }

    /**
     * @param array $smtp
     * @return PHPMailer
     * @throws \PHPMailer\PHPMailer\Exception
     */
    protected function getMailer(array $smtp): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();

        $username = $smtp['username'];
        $password = $smtp['password'];

        $mail->XMailer = 'Appwrite Mailer';
        $mail->Host = $smtp['host'];
        $mail->Port = $smtp['port'];
        $mail->SMTPAuth = (!empty($username) && !empty($password));
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = $smtp['secure'];
        $mail->SMTPAutoTLS = false;
        $mail->SMTPKeepAlive = true;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10; /* Connection timeout */
        $mail->getSMTPInstance()->Timelimit = 30; /* Timeout for each individual SMTP command (e.g. HELO, EHLO, etc.) */

        $mail->setFrom($smtp['senderEmail'], $smtp['senderName']);

        $mail->isHTML();

        return $mail;
    }

    /**
     * @param array<string, mixed> $smtp
     * @param array<string, mixed> $customMailOptions
     * @param string|null $defaultFromEmail
     * @param string|null $defaultFromName
     * @return array<string, string>
     */
    protected function resolveMailOptions(
        array $smtp,
        array $customMailOptions,
        ?string $defaultFromEmail = null,
        ?string $defaultFromName = null
    ): array {
        $defaultFromEmail ??= System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $defaultFromName ??= \urldecode(System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));

        $fromEmail = !empty($smtp['senderEmail']) ? $smtp['senderEmail'] : $defaultFromEmail;
        $fromName = !empty($smtp['senderName']) ? $smtp['senderName'] : $defaultFromName;

        if (!empty($customMailOptions['senderEmail']) || !empty($customMailOptions['senderName'])) {
            $fromEmail = $customMailOptions['senderEmail'] ?? $fromEmail;
            $fromName = $customMailOptions['senderName'] ?? $fromName;
        }

        $replyToEmail = $defaultFromEmail;
        $replyToName = $defaultFromName;

        if (!empty($customMailOptions['replyToEmail']) || !empty($customMailOptions['replyToName'])) {
            $replyToEmail = $customMailOptions['replyToEmail'] ?? $replyToEmail;
            $replyToName = $customMailOptions['replyToName'] ?? $replyToName;
        } elseif (!empty($smtp)) {
            $replyToEmail = !empty($smtp['replyTo']) ? $smtp['replyTo'] : $fromEmail;
            $replyToName = $fromName;
        }

        return [
            'fromEmail' => $fromEmail,
            'fromName' => $fromName,
            'replyToEmail' => $replyToEmail,
            'replyToName' => $replyToName,
        ];
    }

    /**
     * @param array<string, mixed> $attachment
     * @param array<string, string> $mailOptions
     * @throws \PHPMailer\PHPMailer\Exception
     */
    protected function sendWithMailer(
        PHPMailer $mail,
        string $recipient,
        string $name,
        string $subject,
        string $body,
        array $attachment,
        array $mailOptions
    ): void {
        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();
        $mail->addAddress($recipient, $name);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->AltBody = $body;
        $mail->AltBody = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $mail->AltBody);
        $mail->AltBody = \strip_tags($mail->AltBody);
        $mail->AltBody = \trim($mail->AltBody);

        $mail->setFrom($mailOptions['fromEmail'], $mailOptions['fromName']);
        $mail->addReplyTo($mailOptions['replyToEmail'], $mailOptions['replyToName']);

        if (!empty($attachment['content'] ?? '')) {
            $mail->AddStringAttachment(
                base64_decode($attachment['content']),
                $attachment['filename'] ?? 'unknown.file',
                $attachment['encoding'] ?? PHPMailer::ENCODING_BASE64,
                $attachment['type'] ?? 'text/plain'
            );
        }

        $mail->send();
    }

    /**
     * @param array<string, mixed> $attachment
     * @param array<string, string> $mailOptions
     * @throws \Exception
     */
    protected function sendWithAdapter(
        EmailAdapter $adapter,
        string $recipient,
        string $name,
        string $subject,
        string $body,
        array $attachment,
        array $mailOptions
    ): void {
        $tempAttachmentPath = null;
        $attachments = null;

        if (!empty($attachment['content'] ?? '')) {
            $tempAttachmentPath = $this->createAttachmentFile($attachment);
            $attachments = [
                new EmailAttachment(
                    $attachment['filename'] ?? 'unknown.file',
                    $tempAttachmentPath,
                    $attachment['type'] ?? 'text/plain'
                )
            ];
        }

        try {
            // EmailMessage accepts recipients as strings only, so adapter-based sends
            // currently cannot preserve the display name PHPMailer includes in the To header.
            $adapter->send(new EmailMessage(
                [$recipient],
                $subject,
                $body,
                $mailOptions['fromName'],
                $mailOptions['fromEmail'],
                $mailOptions['replyToName'],
                $mailOptions['replyToEmail'],
                null,
                null,
                $attachments,
                // EmailMessage carries a single body plus an HTML flag, so adapter transports
                // cannot send a multipart alternative body until the upstream message model grows one.
                true
            ));
        } finally {
            if ($tempAttachmentPath !== null && \file_exists($tempAttachmentPath)) {
                \unlink($tempAttachmentPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $attachment
     * @return string
     * @throws Exception
     */
    protected function createAttachmentFile(array $attachment): string
    {
        $content = base64_decode($attachment['content'] ?? '', true);

        if ($content === false) {
            throw new Exception('Invalid attachment encoding');
        }

        $path = \tempnam(\sys_get_temp_dir(), 'appwrite-mail-');

        if ($path === false) {
            throw new Exception('Failed to prepare attachment');
        }

        if (\file_put_contents($path, $content) === false) {
            \unlink($path);
            throw new Exception('Failed to prepare attachment');
        }

        return $path;
    }
}
