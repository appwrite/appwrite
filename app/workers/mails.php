<?php

use Appwrite\Template\Template;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;

require_once __DIR__ . '/../worker.php';

Authorization::disable();
Authorization::setDefaultStatus(false);

/**
 * Returns true if all the required terms in a locale exist. False otherwise
 *
 * @param $locale
 * @param $prefix
 *
 * @return bool
 */
Server::setResource('doesLocaleExist', function () {
    return function (Locale $locale, string $prefix) {

        if (!$locale->getText('emails.sender') || !$locale->getText("$prefix.hello") || !$locale->getText("$prefix.subject") || !$locale->getText("$prefix.body") || !$locale->getText("$prefix.footer") || !$locale->getText("$prefix.thanks") || !$locale->getText("$prefix.signature")) {
            return false;
        }

        return true;
    };
});

$server->job()
    ->inject('message')
    ->inject('doesLocaleExist')
    ->inject('register')
    ->action(function (Message $message, callable $doesLocaleExist, Registry $register) {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            Console::info('Skipped mail processing. No SMTP server hostname has been set.');
            return;
        }

        $project = new Document($payload['project'] ?? []);
        $user = new Document($payload['user'] ?? []);
        $team = new Document($payload['team'] ?? []);

        $recipient = $payload['recipient'];
        $url = $payload['url'];
        $name = $payload['name'];
        $type = $payload['type'];

        $prefix = match ($type) {
            MAIL_TYPE_RECOVERY => 'emails.recovery',
            MAIL_TYPE_CERTIFICATE => 'emails.certificate',
            MAIL_TYPE_INVITATION => 'emails.invitation',
            MAIL_TYPE_VERIFICATION => 'emails.verification',
            MAIL_TYPE_MAGIC_SESSION => 'emails.magicSession',
            default => throw new Exception('Undefined Mail Type : ' . $type, 500)
        };

        $locale = new Locale($payload['locale']);
        $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');

    if (!$doesLocaleExist($locale, $prefix)) {
        $locale->setDefault('en');
    }

        $from = $project->isEmpty() || $project->getId() === 'console' ? '' : \sprintf($locale->getText('emails.sender'), $projectName);
        $body = Template::fromFile(__DIR__ . '/../config/locale/templates/email-base.tpl');
        $subject = '';
    switch ($type) {
        case MAIL_TYPE_CERTIFICATE:
            $domain = $payload['domain'];
            $error = $payload['error'];
            $attempt = $payload['attempt'];

            $subject = \sprintf($locale->getText("$prefix.subject"), $domain);
            $body->setParam('{{domain}}', $domain);
            $body->setParam('{{error}}', $error);
            $body->setParam('{{attempt}}', $attempt);
            break;
        case MAIL_TYPE_INVITATION:
            $subject = \sprintf($locale->getText("$prefix.subject"), $team->getAttribute('name'), $projectName);
            $body->setParam('{{owner}}', $user->getAttribute('name'));
            $body->setParam('{{team}}', $team->getAttribute('name'));
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
            ->setParam('{{project}}', $projectName)
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{bg-body}}', '#f7f7f7')
            ->setParam('{{bg-content}}', '#ffffff')
            ->setParam('{{text-content}}', '#000000');

        $body = $body->render();

        /** @var PHPMailer $mail */
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
    });

$server->workerStart();
$server->start();
