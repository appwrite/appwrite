<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Template\Template;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Runtime;
use Utopia\Logger\Log;
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
    public function action(Message $message, Registry $register, Log $log): void
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

        $recipients = $payload['recipients'] ?? [];
        $isBatchMode = !empty($recipients);

        $log->addTag('type', empty($smtp) ? 'cloud' : 'smtp');
        $log->addTag('batch_mode', $isBatchMode ? 'true' : 'false');
        if ($isBatchMode) {
            $log->addTag('recipient_count', (string)count($recipients));
        }

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

        /** @var PHPMailer $mail */
        $mail = empty($smtp)
            ? $register->get('smtp')
            : $this->getMailer($smtp);

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();

        $replyTo = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $replyToName = \urldecode(System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));

        $senderEmail = $payload['senderEmail'] ?? '';
        $senderName = $payload['senderName'] ?? '';

        // fallback hierarchy: Custom options > SMTP config > Defaults.
        if (!empty($senderEmail) || !empty($senderName)) {
            $fromEmail = $senderEmail ?: $mail->From;
            $fromName = $senderName ?: $mail->FromName;
            $mail->setFrom($fromEmail, $fromName);
        }

        if (!empty($smtp)) {
            $replyTo = !empty($smtp['replyTo']) ? $smtp['replyTo'] : ($smtp['senderEmail'] ?? $replyTo);
            $replyToName = $smtp['senderName'] ?? $replyToName;
        }

        $mail->addReplyTo($replyTo, $replyToName);
        if ($isBatchMode && count($recipients) > 1000) {
        throw new Exception('Batch recipient count (' . count($recipients) . ') exceeds maximum allowed (1000). Please split into multiple batches.');
        }
        if ($isBatchMode && empty($smtp)) {
            $this->sendBatch($mail, $recipients, $subject, $body, $attachment, $log);
        } elseif ($isBatchMode && !empty($smtp)) {
            foreach ($recipients as $email => $recipientName) {
                if (is_numeric($email)) {
                    $email = $recipientName;
                    $recipientName = '';
                }
                $this->sendSingle($mail, $email, $recipientName, $subject, $body, $attachment);
            }
        } else {
            $this->sendSingle($mail, $recipient, $name, $subject, $body, $attachment);
        }
    }

    /**
     * Send email to a single recipient
     *
     * @param PHPMailer $mail
     * @param string $recipient
     * @param string $name
     * @param string $subject
     * @param string $body
     * @param array $attachment
     * @throws Exception
     * @return void
     */
    private function sendSingle(
        PHPMailer $mail,
        string $recipient,
        string $name,
        string $subject,
        string $body,
        array $attachment
    ): void {
        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        
        $mail->addAddress($recipient, $name);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $this->prepareAltBody($body);

        if (!empty($attachment['content'] ?? '')) {
            $mail->AddStringAttachment(
                base64_decode($attachment['content']),
                $attachment['filename'] ?? 'unknown.file',
                $attachment['encoding'] ?? PHPMailer::ENCODING_BASE64,
                $attachment['type'] ?? 'plain/text'
            );
        }

        try {
            $mail->send();
        } catch (\Throwable $error) {
            throw new Exception('Error sending mail to ' . $recipient . ': ' . $error->getMessage(), 500);
        }
    }

    /**
     * Send email to multiple recipients using BCC for privacy and efficiency
     * 
     * This method addresses Issue #11023 by batching recipients into a single
     * API call instead of making individual calls that trigger rate limits.
     * 
     * Uses BCC to maintain privacy - recipients don't see each other's addresses.
     * Primary recipient is set to sender to ensure proper SMTP envelope.
     * 
     * Note: Most email APIs support 1000+ recipients per call (Mailgun supports 1000).
     * SMTP servers may have lower limits (often 50-100 depending on provider).
     *
     * @param PHPMailer $mail
     * @param array $recipients Array of email => name pairs or simple email array
     * @param string $subject
     * @param string $body
     * @param array $attachment
     * @param Log $log
     * @throws Exception
     * @return void
     */
    private function sendBatch(
        PHPMailer $mail,
        array $recipients,
        string $subject,
        string $body,
        array $attachment,
        Log $log
    ): void {
        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearBCCs();
        $mail->clearAttachments();
        $mail->addAddress(System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), 'Batch Mail');

        $recipientCount = 0;
        foreach ($recipients as $email => $recipientName) {
            if (is_numeric($email)) {
                $email = $recipientName;
                $recipientName = '';
            }
            $mail->addBCC($email, $recipientName);
            $recipientCount++;
        }

        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $this->prepareAltBody($body);

        if (!empty($attachment['content'] ?? '')) {
            $mail->AddStringAttachment(
                base64_decode($attachment['content']),
                $attachment['filename'] ?? 'unknown.file',
                $attachment['encoding'] ?? PHPMailer::ENCODING_BASE64,
                $attachment['type'] ?? 'plain/text'
            );
        }

        $log->addTag('batch_size', (string)$recipientCount);

        try {
            $mail->send();
            $log->addTag('batch_status', 'success');
        } catch (\Throwable $error) {
            $log->addTag('batch_status', 'failed');
            throw new Exception('Error sending batch mail to ' . $recipientCount . ' recipients: ' . $error->getMessage(), 500);
        }
    }

    /**
     * Prepare plain text alternative body from HTML
     * Removes style tags and strips HTML formatting
     *
     * @param string $body
     * @return string
     */
    private function prepareAltBody(string $body): string
    {
        $altBody = $body;
        $altBody = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $altBody);
        $altBody = \strip_tags($altBody);
        $altBody = \trim($altBody);
        return $altBody;
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
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtp['senderEmail'], $smtp['senderName']);

        $mail->isHTML();

        return $mail;
    }
}
