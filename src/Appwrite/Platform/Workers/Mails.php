<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Template\Template;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Runtime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;

class Mails extends Action
{
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
            ->callback(fn($message, $register) => $this->action($message, $register));
    }

    /**
     * @param Message $message
     * @param Registry $register
     * @throws \PHPMailer\PHPMailer\Exception
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Registry $register): void
    {
        Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $smtp = $payload['smtp'];

        if (empty($smtp) && empty(App::getEnv('_APP_SMTP_HOST'))) {
            Console::info('Skipped mail processing. No SMTP configuration has been set.');
            return;
        }

        $recipient = $payload['recipient'];
        $subject = $payload['subject'];
        $variables = $payload['variables'];
        $name = $payload['name'];
        $body = $payload['body'];
        $attachment = $payload['attachment'] ?? [];

        $bodyTemplate = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-base.tpl');
        $bodyTemplate->setParam('{{body}}', $body);
        foreach ($variables as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value);
        }
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
        $mail->addAddress($recipient, $name);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = \strip_tags($body);
        if (!empty($attachment['content'] ?? '')) {
            $mail->AddStringAttachment(
                $attachment['content'],
                $attachment['filename'] ?? 'unknown.file',
                $attachment['encoding'] ?? PHPMailer::ENCODING_BASE64,
                $attachment['type'] ?? 'plain/text'
            );
        }

        try {
            $mail->send();
        } catch (\Exception $error) {
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
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtp['senderEmail'], $smtp['senderName']);

        if (!empty($smtp['replyTo'])) {
            $mail->addReplyTo($smtp['replyTo'], $smtp['senderName']);
        }

        $mail->isHTML();

        return $mail;
    }
}
