<?php

use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../init.php';

Console::title('Mails V1 Worker');
Console::success(APP_NAME . ' mails worker v1 has started' . "\n");

class MailsV1 extends Worker
{
    public function getName(): string
    {
        return "mails";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        global $register;

        $smtp = $this->args['smtp'];

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            Console::info('Skipped mail processing. No SMTP configuration has been set.');
            return;
        }


        $recipient = $this->args['recipient'];
        $subject = $this->args['subject'];
        $name = $this->args['name'];
        $body = $this->args['body'];
        $from = $this->args['from'];

        /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
        $mail = empty($smtp) ? $register->get('smtp') : $this->getMailer($smtp);

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();

        $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), (empty($from) ? \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server')) : $from));
        $mail->addAddress($recipient, $name);
        if (isset($smtp['replyTo'])) {
            $mail->addReplyTo($smtp['replyTo']);
        }
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = \strip_tags($body);

        try {
            $mail->send();
        } catch (\Exception $error) {
            throw new Exception('Error sending mail: ' . $error->getMessage(), 500);
        }
    }

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
        $mail->SMTPSecure = $smtp['secure'] === 'tls';
        $mail->SMTPAutoTLS = false;
        $mail->CharSet = 'UTF-8';

        $from = \urldecode($smtp['senderName'] ?? App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));
        $email = $smtp['senderEmail'] ?? App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

        $mail->setFrom($email, $from);
        $mail->addReplyTo($email, $from);

        $mail->isHTML(true);

        return $mail;
    }

    public function shutdown(): void
    {
    }
}
