<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Validator\Email;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectEmailTemplate';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email/:templateId/:locale')
            ->desc('Update project email template')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.write')
            ->label('event', 'templates.[templateId].update')
            ->label('audits.event', 'project.template.update')
            ->label('audits.resource', 'project.template/{response.templateId}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'updateEmailTemplate',
                description: <<<EOT
                Update a custom email template for the specified locale and type. Use this endpoint to modify the content of your email templates. Pass `reset=true` to remove all customisations and restore Appwrite defaults.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE,
                    )
                ]
            ))
            ->param('templateId', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Custom email template type. Can be one of: '.\implode(', ', Config::getParam('locale-templates')['email'] ?? []))
            ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Custom email template locale. If left empty, the fallback locale (en) will be used.', optional: true, injections: ['localeCodes'])
            ->param('subject', null, new Nullable(new Text(255)), 'Subject of the email template. Can be up to 255 characters.', optional: true)
            ->param('message', null, new Nullable(new Text(10485760)), 'Plain or HTML body of the email template message. Can be up to 10MB of content.', optional: true)
            ->param('senderName', null, new Nullable(new Text(255, 0)), 'Name of the email sender.', optional: true)
            ->param('senderEmail', null, new Nullable(new Email()), 'Email of the sender.', optional: true)
            ->param('replyToEmail', null, new Nullable(new Email()), 'Reply to email.', optional: true)
            ->param('replyToName', null, new Nullable(new Text(255, 0)), 'Reply to name.', optional: true)
            ->param('reset', false, new Boolean(), 'Reset template to Appwrite defaults. Removes all customisations for the given template and locale. When set to true, all other parameters are ignored.', optional: true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $templateId,
        string $locale,
        ?string $subject,
        ?string $message,
        ?string $senderName,
        ?string $senderEmail,
        ?string $replyToEmail,
        ?string $replyToName,
        bool $reset,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
    ) {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

        $templates = $project->getAttribute('templates', []);

        if ($reset) {
            // Remove custom override — no SMTP check needed to restore defaults
            unset($templates['email.' . $templateId . '-' . $locale]);

            $updates = new Document(['templates' => $templates]);
            $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

            $queueForEvents->setParam('templateId', $templateId);

            $localeObj = new Locale($locale);
            $localeObj->setFallback(System::getEnv('_APP_LOCALE', 'en'));

            $response->dynamic(new Document([
                'templateId'   => $templateId,
                'locale'       => $locale,
                'subject'      => $localeObj->getText('emails.' . $templateId . '.subject'),
                'message'      => $this->getDefaultMessage($templateId, $localeObj),
                'senderName'   => '',
                'senderEmail'  => '',
                'replyToEmail' => '',
                'replyToName'  => '',
            ]), Response::MODEL_EMAIL_TEMPLATE);

            return;
        }

        // Prevent template update if custom SMTP is not configured
        $smtp = $project->getAttribute('smtp', []);
        if (($smtp['enabled'] ?? false) !== true) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'SMTP must be enabled on the project to configure custom email templates.');
        }

        // Fetch current configuration
        $template = $templates['email.' . $templateId . '-' . $locale] ?? [];

        // Apply changes
        $keys = ['senderName', 'senderEmail', 'replyToEmail', 'replyToName', 'message', 'subject'];
        foreach ($keys as $key) {
            if (!\is_null(${$key})) {
                $template[$key] = ${$key};
            }
        }

        // Backwards compatibility
        if (!\is_null($template['replyTo'] ?? null)) {
            $template['replyToEmail'] = $template['replyToEmail'] ?? $template['replyTo'] ?? '';
        }

        // Ensure required fields are set
        $requiredKeys = ['subject', 'message'];
        foreach ($requiredKeys as $key) {
            if (empty($template[$key])) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "' . $key . '" is not optional.');
            }
        }

        // Save configuration
        $templates['email.' . $templateId . '-' . $locale] = $template;
        $updates = new Document([
            'templates' => $templates,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents->setParam('templateId', $templateId);

        $response->dynamic(new Document([
            'templateId' => $templateId,
            'locale' => $locale,
            'subject' => $template['subject'],
            'message' => $template['message'],
            'senderName' => $template['senderName'] ?? '',
            'senderEmail' => $template['senderEmail'] ?? '',
            'replyToEmail' => $template['replyToEmail'] ?? '',
            'replyToName' => $template['replyToName'] ?? '',
        ]), Response::MODEL_EMAIL_TEMPLATE);
    }

    private function getDefaultMessage(string $templateId, Locale $localeObj): string
    {
        $templateConfigs = [
            'magicSession' => [
                'file' => 'email-magic-url.tpl',
                'placeholders' => ['optionButton', 'buttonText', 'optionUrl', 'clientInfo', 'securityPhrase']
            ],
            'mfaChallenge' => [
                'file' => 'email-mfa-challenge.tpl',
                'placeholders' => ['description', 'clientInfo']
            ],
            'otpSession' => [
                'file' => 'email-otp.tpl',
                'placeholders' => ['description', 'clientInfo', 'securityPhrase']
            ],
            'sessionAlert' => [
                'file' => 'email-session-alert.tpl',
                'placeholders' => ['body', 'listDevice', 'listIpAddress', 'listCountry', 'footer']
            ],
        ];

        $config = $templateConfigs[$templateId] ?? [
            'file' => 'email-inner-base.tpl',
            'placeholders' => ['buttonText', 'body', 'footer']
        ];

        $templateString = file_get_contents(APP_CE_CONFIG_DIR . '/locale/templates/' . $config['file']);
        $message = Template::fromString($templateString);

        foreach ($config['placeholders'] as $param) {
            $escapeHtml = !in_array($param, ['clientInfo', 'body', 'footer', 'description']);
            if ($templateId === 'magicSession' && $param === 'securityPhrase') {
                $message->setParam('{{securityPhrase}}', '');
                continue;
            }

            $message->setParam("{{{$param}}}", $localeObj->getText("emails.{$templateId}.{$param}"), escapeHtml: $escapeHtml);
        }

        $message
            ->setParam('{{hello}}', $localeObj->getText("emails.{$templateId}.hello"))
            ->setParam('{{thanks}}', $localeObj->getText("emails.{$templateId}.thanks"))
            ->setParam('{{signature}}', $localeObj->getText("emails.{$templateId}.signature"));

        return $message->render(useContent: true);
    }
}
