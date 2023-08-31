<?php

use Appwrite\Resque\Worker;
use Appwrite\Template\Template;
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

        if (empty($smtp) && empty(App::getEnv('_APP_SMTP_HOST'))) {
            Console::info('Skipped mail processing. No SMTP configuration has been set.');
            return;
        }

        $recipient = $this->args['recipient'];
        $subject = $this->args['subject'];
        $body = $this->args['body'];
        $variables = $this->args['variables'];
        $name = $this->args['name'];

        $body = Template::fromFile(__DIR__ . '/../config/locale/templates/email-base.tpl');

        foreach ($variables as $key => $value) {
            $body->setParam('{{' . $key . '}}', $value);
        }

        $body = $body->render();

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

    public function shutdown(): void
    {
    }
}
