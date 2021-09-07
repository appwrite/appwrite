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

        if (!$this->doesLocaleExist($locale, $type)) {
            $locale->setDefault('en');
        }

        $from = $this->args['from'] === 'console' ? '' : \sprintf($locale->getText('emails.sender'), $project);
        $body = Template::fromFile(__DIR__ . '/../config/locale/templates/email-base.tpl');
        $subject = '';
        switch ($type) {
            case MAIL_TYPE_RECOVERY:
                $subject = $locale->getText($this->getTranslationKey($type, "subject"));
                break;
            case MAIL_TYPE_INVITATION:
                $subject = \sprintf($locale->getText($this->getTranslationKey($type, "subject")), $this->args['team'], $project);
                $body->setParam('{{owner}}', $this->args['owner']);
                $body->setParam('{{team}}', $this->args['team']);
                break;
            case MAIL_TYPE_VERIFICATION:
                $subject = $locale->getText($this->getTranslationKey($type, "subject"));
            case MAIL_TYPE_MAGIC_SESSION:
                $subject = $locale->getText($this->getTranslationKey($type, "subject"));
                break;
            default:
                throw new Exception('Undefined Mail Type : ' . $type, 500);
        }

        $body
            ->setParam('{{subject}}', $subject)
            ->setParam('{{hello}}', $locale->getText($this->getTranslationKey($type, "hello")))
            ->setParam('{{name}}', $name)
            ->setParam('{{body}}', $locale->getText($this->getTranslationKey($type, "body")))
            ->setParam('{{redirect}}', $url)
            ->setParam('{{footer}}', $locale->getText($this->getTranslationKey($type, "footer")))
            ->setParam('{{thanks}}', $locale->getText($this->getTranslationKey($type, "thanks")))
            ->setParam('{{signature}}', $locale->getText($this->getTranslationKey($type, "signature")))
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
     * Returns a key that can be used to get translation from $locale->geText().
     * $keyType can currently be one of: subject, hello, body, footer, thanks, signature
     *
     * @param $emailType
     * @param $keyType
     *
     * @return string
     */
    protected function getTranslationKey(string $emailType, $keyType): string
    {
        switch ($emailType) {
            case MAIL_TYPE_RECOVERY:
                $keys = [
                    'subject' => 'emails.recovery.subject',
                    'hello' => 'emails.recovery.hello',
                    'body' => 'emails.recovery.body',
                    'footer' => 'emails.recovery.footer',
                    'thanks' => 'emails.recovery.thanks',
                    'signature' => 'emails.recovery.signature',
                ];

                return $keys[$keyType];
            case MAIL_TYPE_INVITATION:
                $keys = [
                    'subject' => 'emails.invitation.subject',
                    'hello' => 'emails.invitation.hello',
                    'body' => 'emails.invitation.body',
                    'footer' => 'emails.invitation.footer',
                    'thanks' => 'emails.invitation.thanks',
                    'signature' => 'emails.invitation.signature',
                ];

                return $keys[$keyType];
            case MAIL_TYPE_MAGIC_SESSION:
                $keys = [
                    'subject' => 'emails.magicSession.subject',
                    'hello' => 'emails.magicSession.hello',
                    'body' => 'emails.magicSession.body',
                    'footer' => 'emails.magicSession.footer',
                    'thanks' => 'emails.magicSession.thanks',
                    'signature' => 'emails.magicSession.signature',
                ];

                return $keys[$keyType];
            case MAIL_TYPE_VERIFICATION:
                $keys = [
                    'subject' => 'emails.verification.subject',
                    'hello' => 'emails.verification.hello',
                    'body' => 'emails.verification.body',
                    'footer' => 'emails.verification.footer',
                    'thanks' => 'emails.verification.thanks',
                    'signature' => 'emails.verification.signature',
                ];

                return $keys[$keyType];
            default:
                throw new Exception('Undefined Mail Type : ' . $emailType, 500);
        }
    }

    /**
     * Returns true if all the required terms in a locale exist. False otherwise
     * 
     * @param $locale
     * @param $type
     * 
     * @return bool
     */
    protected function doesLocaleExist(Locale $locale, string $type): bool
    {

        if (
            !$locale->getText('emails.sender') ||
            !$locale->getText($this->getTranslationKey($type, "hello")) ||
            !$locale->getText($this->getTranslationKey($type, "subject")) ||
            !$locale->getText($this->getTranslationKey($type, "body")) ||
            !$locale->getText($this->getTranslationKey($type, "footer")) ||
            !$locale->getText($this->getTranslationKey($type, "thanks")) ||
            !$locale->getText($this->getTranslationKey($type, "signature"))
        ) {
            return false;
        }

        return true;
    }
}
