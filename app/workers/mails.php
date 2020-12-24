<?php

use Utopia\App;
use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Template\Template;
use Appwrite\Template\Inky;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Appwrite\Database\Validator\Authorization;

require_once __DIR__.'/../init.php';

\cli_set_process_title('Mails V1 Worker');

echo APP_NAME.' mails worker v1 has started'."\n";

class MailsV1
{
    /**
     * @var array
     */
    public $args = [];

    public function setUp(): void
    {
    }

    public function perform()
    {
        global $register;

        $locale = new Locale('en');
        $events = (object) [
            'account.verification.create' => (object) [
                'title' => 'account.emails.verification.title', 
                'body' => 'account.emails.verification.body'
            ],
            'account.recovery.create' => (object) [
                'title' => 'account.emails.recovery.title',
                'body' => 'account.emails.recovery.body'
            ],
            'teams.membership.create' => (object) [
                'title' => 'account.emails.invitation.title', 
                'body' => 'account.emails.invitation.body'
            ]
        ];

        
        $consoleDB = new Database();
        $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $consoleDB->setNamespace('app_console'); // Main DB
        $consoleDB->setMocks(Config::getParam('collections', []));

        $projectId = $this->args['projectId'];
        $event = $this->args['event'];
        $recipient = $this->args['recipient'];
        $name = $this->args['name'];
        $url = $this->args['url'];

        $body = new Inky(__DIR__.'/../config/locale/templates/email-base.tpl');
        $cta = new Template(__DIR__.'/../config/locale/templates/email-cta.tpl');
        $current = $events->{$event};

        Authorization::disable();
        $project = $consoleDB->getDocument($projectId);
        Authorization::reset();

        $from = ($project->getId() === 'console') ? '' : \sprintf($locale->getText('account.emails.team'), $project->getAttribute('name'));
        $subject = $locale->getText($current->title);
        $content = new Template(__DIR__.'/../config/locale/translations/templates/'.$locale->getText($current->body));

        $body
            ->setParam('{{content}}', $content->render())
            ->setParam('{{cta}}', $cta->render())
            ->setParam('{{title}}', $subject)
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('{{name}}', $name)
            ->setParam('{{redirect}}', $url)
            ->setParam('{{colorText}}', $project->getAttribute('colorText', ['#000000']))
            ->setParam('{{colorTextPrimary}}', $project->getAttribute('colorTextPrimary', ['#ffffff']))
            ->setParam('{{colorBg}}', $project->getAttribute('colorBg', ['#f6f6f6']))
            ->setParam('{{colorBgContent}}', $project->getAttribute('colorBgContent', ['#ffffff']))
            ->setParam('{{colorBgPrimary}}', $project->getAttribute('colorBgPrimary', ['#3498db']))
        ;

        $transpiledBody = $body->transpileInky();
        
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
        $mail->Body = $transpiledBody;
        $mail->AltBody = \strip_tags($transpiledBody);

        try {
            $mail->send();
        } catch (\Exception $error) {
            throw new Exception('Error sending mail: ' . $error->getMessage(), 500);
        }
    }

    public function tearDown(): void
    {
        // ... Remove environment for this job
    }
}
