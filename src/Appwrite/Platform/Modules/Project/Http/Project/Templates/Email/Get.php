<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectEmailTemplate';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/templates/email/:templateId')
            ->httpAlias('/v1/projects/:projectId/templates/email/:templateId/:locale')
            ->desc('Get project email template')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'getEmailTemplate',
                description: <<<EOT
                Get a custom email template for the specified locale and type. This endpoint returns the template content, subject, and other configuration details.
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
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $templateId,
        string $locale,
        Response $response,
        Document $project,
    ) {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

        // Get custom template if available
        $templates = $project->getAttribute('templates', []);
        $template = $templates['email.' . $templateId . '-' . $locale] ?? [];

        // Enforced params
        $template['templateId'] = $templateId;
        $template['locale'] = $locale;

        // Prepare default tempaltes
        $localeObj = new Locale($locale);
        $localeObj->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        $defaultSubject = $localeObj->getText('emails.' . $templateId . '.subject');
        $defaultMessage = $this->getDefaultMessage($templateId, $localeObj);

        // Apply defaults if needed
        if (\is_null($template['message'] ?? null)) {
            $template['message'] = $defaultMessage;
        }

        if (\is_null($template['subject'] ?? null)) {
            $template['subject'] = $defaultSubject;
        }

        // Backwards compatibility
        if (!\is_null($template['replyTo'] ?? null)) {
            $template['replyToEmail'] = $template['replyToEmail'] ?? $template['replyTo'] ?? '';
        }

        $response->dynamic(new Document($template), Response::MODEL_EMAIL_TEMPLATE);
    }

    protected function getDefaultMessage(string $templateId, Locale $localeObj): string
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

        // fallback to the base template.
        $config = $templateConfigs[$templateId] ?? [
            'file' => 'email-inner-base.tpl',
            'placeholders' => ['buttonText', 'body', 'footer']
        ];

        $templateString = file_get_contents(APP_CE_CONFIG_DIR . '/locale/templates/' . $config['file']);
        $message = Template::fromString($templateString);

        // Set type-specific parameters
        foreach ($config['placeholders'] as $param) {
            $escapeHtml = !in_array($param, ['clientInfo', 'body', 'footer', 'description']);
            $message->setParam("{{{$param}}}", $localeObj->getText("emails.{$templateId}.{$param}"), escapeHtml: $escapeHtml);
        }

        $message
            ->setParam('{{hello}}', $localeObj->getText("emails.{$templateId}.hello"))
            ->setParam('{{thanks}}', $localeObj->getText("emails.{$templateId}.thanks"))
            ->setParam('{{signature}}', $localeObj->getText("emails.{$templateId}.signature"));

        $message = $message->render(useContent: true);

        return $message;
    }
}
