<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\InMemoryQuery;
use Appwrite\Utopia\Database\Validator\Queries\ProjectTemplates;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectEmailTemplates';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/templates/email')
            ->desc('List project email templates')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'listEmailTemplates',
                description: <<<EOT
                Get a list of all email templates available for the project. Each entry represents a type and locale combination, with the `custom` flag indicating whether the template has been customized.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE_LIST,
                    )
                ]
            ))
            ->param('queries', [], new ProjectTemplates(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter and order on the following attributes: ' . implode(', ', array_keys(ProjectTemplates::ALLOWED_ATTRIBUTES)), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('project')
            ->inject('response')
            ->inject('localeCodes')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     * @param array<string> $localeCodes
     */
    public function action(
        array $queries,
        bool $includeTotal,
        Document $project,
        Response $response,
        array $localeCodes,
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);

        $types = Config::getParam('locale-templates')['email'] ?? [];
        $projectTemplates = $project->getAttribute('templates', []);

        $templates = [];
        foreach ($types as $type) {
            foreach ($localeCodes as $locale) {
                $key = 'email.' . $type . '-' . $locale;
                $stored = $projectTemplates[$key] ?? null;

                $localeObj = new Locale($locale);
                $localeObj->setFallback(System::getEnv('_APP_LOCALE', 'en'));

                if (is_null($stored)) {
                    /**
                     * different templates, different placeholders.
                     */
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
                    $config = $templateConfigs[$type] ?? [
                        'file' => 'email-inner-base.tpl',
                        'placeholders' => ['buttonText', 'body', 'footer']
                    ];

                    $templateString = file_get_contents(APP_CE_CONFIG_DIR . '/locale/templates/' . $config['file']);

                    // We use `fromString` due to the replace above
                    $message = Template::fromString($templateString);

                    // Set type-specific parameters
                    foreach ($config['placeholders'] as $param) {
                        $escapeHtml = !in_array($param, ['clientInfo', 'body', 'footer', 'description']);
                        $message->setParam("{{{$param}}}", $localeObj->getText("emails.{$type}.{$param}"), escapeHtml: $escapeHtml);
                    }

                    $message
                        // common placeholders on all the templates
                        ->setParam('{{hello}}', $localeObj->getText("emails.{$type}.hello"))
                        ->setParam('{{thanks}}', $localeObj->getText("emails.{$type}.thanks"))
                        ->setParam('{{signature}}', $localeObj->getText("emails.{$type}.signature"));

                    // `useContent: false` will strip new lines!
                    $message = $message->render(useContent: true);

                    $template = [
                        'message' => $message,
                        'subject' => $localeObj->getText('emails.' . $type . '.subject'),
                        'senderEmail' => '',
                        'senderName' => '',
                        'custom' => false,
                    ];
                } else {
                    $template = $stored;
                    $template['custom'] = true;
                }

                $template['type'] = $type;
                $template['locale'] = $locale;

                $templates[] = new Document($template);
            }
        }

        $templates = InMemoryQuery::filter($templates, $grouped['filters']);
        $templates = InMemoryQuery::order($templates, $grouped['orderAttributes'], $grouped['orderTypes']);

        $total = $includeTotal ? \count($templates) : 0;
        $templates = InMemoryQuery::paginate($templates, $grouped['limit'] ?? APP_LIMIT_COUNT, $grouped['offset']);

        $response->dynamic(new Document([
            'templates' => $templates,
            'total' => $total,
        ]), Response::MODEL_EMAIL_TEMPLATE_LIST);
    }
}
