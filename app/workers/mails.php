<?php

use Appwrite\Resque\Worker;
use Appwrite\Template\Template;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Locale\Locale;

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

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            Console::info('Skipped mail processing. No SMTP server hostname has been set.');
            return;
        }

        $template = $this->args['template'] ?? __DIR__ . '/../config/locale/templates/email-base.tpl';
        $prefix = $this->args['localeMessagePrefix'];
        $locale = new Locale($this->args['locale']);
        $recipient = $this->args['recipient'];
        $name = $this->args['name'];
        $from = $this->args['from'];
        $subject = $this->args['subject'];

        if (!$this->doesLocaleExist($locale, $prefix)) {
            $locale->setDefault('en');
        }
        
        $body = Template::fromFile($template);
        foreach ($this->args as $key => $value) {
            $body->setParam('{{' . $key . '}}', $value);
        }

        $body
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{bg-body}}', '#f7f7f7')
            ->setParam('{{bg-content}}', '#ffffff')
            ->setParam('{{text-content}}', '#000000');
        
        $body = $body->render();

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

        $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), (empty($from) ? \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server')) : $from));
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

    public function shutdown(): void
    {
    }

    /**
     * Returns a prefix from a mail type
     *
     * @param $type
     *
     * @return string
     */
    protected function getPrefix(string $type): string
    {
        switch ($type) {
            case MAIL_TYPE_RECOVERY:
                return 'emails.recovery';
            case MAIL_TYPE_CERTIFICATE:
                return 'emails.certificate';
            case MAIL_TYPE_INVITATION:
                return 'emails.invitation';
            case MAIL_TYPE_VERIFICATION:
                return 'emails.verification';
            case MAIL_TYPE_MAGIC_SESSION:
                return 'emails.magicSession';
            default:
                throw new Exception('Undefined Mail Type : ' . $type, 500);
        }
    }

    /**
     * Returns true if all the required terms in a locale exist. False otherwise
     *
     * @param $locale
     * @param $prefix
     *
     * @return bool
     */
    protected function doesLocaleExist(Locale $locale, string $prefix): bool
    {

        if (!$locale->getText('emails.sender') || !$locale->getText("$prefix.hello") || !$locale->getText("$prefix.subject") || !$locale->getText("$prefix.body") || !$locale->getText("$prefix.footer") || !$locale->getText("$prefix.thanks") || !$locale->getText("$prefix.signature")) {
            return false;
        }

        return true;
    }
}
