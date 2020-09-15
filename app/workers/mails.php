<?php

use Utopia\App;

require_once __DIR__.'/../init.php';

\cli_set_process_title('Mails V1 Worker');

echo APP_NAME.' mails worker v1 has started'."\n";

class MailsV1
{
    /**
     * @var array
     */
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;

        $event = $this->args['event'];
        $from = $this->args['from'];
        $recipient = $this->args['recipient'];
        $name = $this->args['name'];
        $subject = $this->args['subject'];
        $body = $this->args['body'];
        
        /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
        $mail = $register->get('smtp');

        // Set project mail
        /*$register->get('smtp')
            ->setFrom(
                App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM),
                ($project->getId() === 'console')
                    ? \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server'))
                    : \sprintf(Locale::getText('account.emails.team'), $project->getAttribute('name')
                )
            );*/

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();

        $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), (empty($from) ? \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server')) : $from));
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

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
