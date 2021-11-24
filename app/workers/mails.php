<?php

use Appwrite\Resque\Worker;
use Appwrite\Template\Template;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Locale\Locale;

require_once __DIR__ . '/../workers.php';

Console::title('Mails V1 Worker');
Console::success(APP_NAME . ' mails worker v1 has started' . "\n");

class MailsV1 extends Worker
{
    public function getName(): string {
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

        $recipient = $this->args['recipient'];
        $name = $this->args['name'];
        $url = $this->args['url'];
        $project = $this->args['project'];
        $type = $this->args['type'];
        $prefix = $this->getPrefix($type);
        $locale = new Locale($this->args['locale']);

        if (!$this->doesLocaleExist($locale, $prefix)) {
            $locale->setDefault('en');
        }

        $from = $this->args['from'] === 'console' ? '' : \sprintf($locale->getText('emails.sender'), $project);
        $body = Template::fromFile(__DIR__ . '/../config/locale/templates/email-base.tpl');
        $subject = '';
        switch ($type) {
            case MAIL_TYPE_INVITATION:
                $subject = \sprintf($locale->getText("$prefix.subject"), $this->args['team'], $project);
                $body->setParam('{{owner}}', $this->args['owner']);
                $body->setParam('{{team}}', $this->args['team']);
                break;
            case MAIL_TYPE_RECOVERY:
            case MAIL_TYPE_VERIFICATION:
            case MAIL_TYPE_MAGIC_SESSION:
                $subject = $locale->getText("$prefix.subject");
                break;
            default:
                throw new Exception('Undefined Mail Type : ' . $type, 500);
        }

        $body
            ->setParam('{{subject}}', $subject)
            ->setParam('{{hello}}', $locale->getText("$prefix.hello"))
            ->setParam('{{name}}', $name)
            ->setParam('{{body}}', $locale->getText("$prefix.body"))
            ->setParam('{{redirect}}', $url)
            ->setParam('{{footer}}', $locale->getText("$prefix.footer"))
            ->setParam('{{thanks}}', $locale->getText("$prefix.thanks"))
            ->setParam('{{signature}}', $locale->getText("$prefix.signature"))
            ->setParam('{{project}}', $project)
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
