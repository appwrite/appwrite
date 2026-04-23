<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
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
                Get a list of all custom email templates configured for the project. This endpoint returns an array of all configured email templates and their locales.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE_LIST,
                    )
                ]
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        bool $includeTotal,
        Response $response,
        Document $project,
    ) {
        $templates = $project->getAttribute('templates', []);

        $emailTemplates = [];
        foreach ($templates as $key => $template) {
            if (!\str_starts_with($key, 'email.')) {
                continue;
            }

            $suffix = \substr($key, \strlen('email.'));
            $parts = \explode('-', $suffix, 2);
            if (\count($parts) !== 2) {
                continue;
            }

            [$templateId, $locale] = $parts;

            $template['templateId'] = $templateId;
            $template['locale'] = $locale;

            // Backwards compatibility
            if (!\is_null($template['replyTo'] ?? null)) {
                $template['replyToEmail'] = $template['replyToEmail'] ?? $template['replyTo'] ?? '';
            }

            $emailTemplates[] = new Document($template);
        }

        $total = $includeTotal ? \count($emailTemplates) : 0;

        $response->dynamic(new Document([
            'templates' => $emailTemplates,
            'total' => $total,
        ]), Response::MODEL_EMAIL_TEMPLATE_LIST);
    }
}
