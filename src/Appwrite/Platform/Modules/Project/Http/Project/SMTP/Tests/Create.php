<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\SMTP\Tests;

use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Extend\Exception as Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Document;
use Utopia\Emails\Validator\Email;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectSMTPTest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/smtp/tests')
            ->httpAlias('/v1/projects/:projectId/smtp/tests')
            ->desc('Create project SMTP test')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'smtp',
                name: 'createSMTPTest',
                description: <<<EOT
                Send a test email to verify SMTP configuration. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
            ))
            ->param('emails', [], new ArrayList(new Email(), 10), 'Array of emails to send test email to. Maximum of 10 emails are allowed.')
            ->param('senderName', '', new Text(256), 'Name of the email sender', optional: true, deprecated: true) // Backwards compatibility
            ->param('senderEmail', '', new Email(), 'Email of the sender', optional: true, deprecated: true) // Backwards compatibility
            ->param('replyTo', '', new Email(), 'Reply to email', optional: true, deprecated: true) // Backwards compatibility
            ->param('host', '', new Hostname(), 'SMTP server host name', optional: true, deprecated: true) // Backwards compatibility
            ->param('port', null, new Integer(), 'SMTP server port', optional: true, deprecated: true) // Backwards compatibility
            ->param('username', '', new Text(256), 'SMTP server username', optional: true, deprecated: true) // Backwards compatibility
            ->param('password', '', new Text(256), 'SMTP server password', optional: true, deprecated: true) // Backwards compatibility
            ->param('secure', '', new WhiteList(['tls', 'ssl'], true), 'Does SMTP server use secure connection', optional: true, deprecated: true) // Backwards compatibility
            ->inject('response')
            ->inject('project')
            ->inject('publisherForMails')
            ->inject('plan')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $emails
     */
    public function action(
        array $emails,
        string $paramSenderName, // Backwards compatibility
        string $paramSenderEmail, // Backwards compatibility
        string $paramReplyTo, // Backwards compatibility
        string $paramHost, // Backwards compatibility
        ?int $paramPort, // Backwards compatibility
        string $paramUsername, // Backwards compatibility
        string $paramPassword, // Backwards compatibility
        string $paramSecure, // Backwards compatibility
        Response $response,
        Document $project,
        MailPublisher $publisherForMails,
        array $plan
    ): void {
        // Backwards compatibility: use inline params if provided, otherwise fall back to project SMTP config.
        // When inline params are provided they are treated as self-contained — project config is ignored
        // so legacy (1.9.1) callers do not get project state (e.g. replyToName) leaked into their request.
        $hasInlineParams = !empty($paramHost);

        $smtp = $project->getAttribute('smtp', []);

        if (!$hasInlineParams && ($smtp['enabled'] ?? false) !== true) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP must be enabled on the project to send a test email.');
        }

        if ($hasInlineParams) {
            $senderName = $paramSenderName;
            $senderEmail = $paramSenderEmail;
            $replyToEmail = $paramReplyTo;
            $replyToName = ''; // 1.9.1 inline params did not include replyToName
            $host = $paramHost;
            $port = $paramPort ?? 0;
            $username = $paramUsername;
            $password = $paramPassword;
            $secure = $paramSecure;
        } else {
            $senderName = $smtp['senderName'] ?? '';
            $senderEmail = $smtp['senderEmail'] ?? '';
            $replyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? ''; // Includes backwards compatibility
            $replyToName = $smtp['replyToName'] ?? '';
            $host = $smtp['host'] ?? '';
            $port = $smtp['port'] ?? 0;
            $username = $smtp['username'] ?? '';
            $password = $smtp['password'] ?? '';
            $secure = $smtp['secure'] ?? '';
        }

        if (empty($senderEmail)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP sender email must be configured on the project to send a test email.');
        }

        if (empty($host)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP host must be configured on the project to send a test email.');
        }

        if (empty($port)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP port must be configured on the project to send a test email.');
        }

        // Fallback to sender details when reply-to is not explicitly configured
        $replyToEmailDisplay = !empty($replyToEmail) ? $replyToEmail : $senderEmail;
        $replyToNameDisplay = !empty($replyToName) ? $replyToName : $senderName;

        $subject = 'Custom SMTP email sample';
        $template = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/email-smtp-test.tpl');
        $template
            ->setParam('{{from}}', "{$senderName} ({$senderEmail})")
            ->setParam('{{replyTo}}', "{$replyToNameDisplay} ({$replyToEmailDisplay})")
            ->setParam('{{logoUrl}}', $plan['logoUrl'] ?? APP_EMAIL_LOGO_URL)
            ->setParam('{{accentColor}}', $plan['accentColor'] ?? APP_EMAIL_ACCENT_COLOR)
            ->setParam('{{twitterUrl}}', $plan['twitterUrl'] ?? APP_SOCIAL_TWITTER)
            ->setParam('{{discordUrl}}', $plan['discordUrl'] ?? APP_SOCIAL_DISCORD)
            ->setParam('{{githubUrl}}', $plan['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE)
            ->setParam('{{termsUrl}}', $plan['termsUrl'] ?? APP_EMAIL_TERMS_URL)
            ->setParam('{{privacyUrl}}', $plan['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL);

        foreach ($emails as $email) {
            $publisherForMails->enqueue(new MailMessage(
                project: $project,
                recipient: $email,
                subject: $subject,
                bodyTemplate: APP_CE_CONFIG_DIR . '/locale/templates/email-base-styled.tpl',
                body: $template->render(),
                smtp: [
                    'host' => $host,
                    'port' => $port,
                    'username' => $username,
                    'password' => $password,
                    'secure' => $secure,
                    'replyToEmail' => $replyToEmail,
                    'replyToName' => $replyToName,
                    'senderEmail' => $senderEmail,
                    'senderName' => $senderName,
                ],
            ));
        }

        $response->noContent();
    }
}
