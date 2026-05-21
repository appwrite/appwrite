<?php

namespace Appwrite\Platform\Modules\Console\Http\Templates\Email;

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

    public static function getName(): string
    {
        return 'getConsoleEmailTemplate';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/templates/email/:templateId')
            ->desc('Get email template')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'console',
                group: null,
                name: 'getEmailTemplate',
                description: <<<EOT
                Get the Appwrite built-in default email template for the specified type and locale. Always returns the unmodified default, ignoring any custom project overrides.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE,
                    )
                ]
            ))
            ->param('templateId', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Email template type. Can be one of: ' . \implode(', ', Config::getParam('locale-templates')['email'] ?? []))
            ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale. If left empty, the fallback locale (en) will be used.', optional: true, injections: ['localeCodes'])
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(
        string $templateId,
        string $locale,
        Response $response,
    ): void {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

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
