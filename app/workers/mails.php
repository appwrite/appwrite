<?php

require_once __DIR__.'/../init.php';

cli_set_process_title('Mails V1 Worker');

echo APP_NAME.' mails worker v1 has started';

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

        $recipient = $this->args['recipient'];
        $name = $this->args['name'];
        $subject = $this->args['subject'];
        $body = $this->args['body'];
        
        $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

        $mail->addAddress($recipient, $name);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

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
