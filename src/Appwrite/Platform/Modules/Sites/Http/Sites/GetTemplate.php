<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class GetTemplate extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getTemplate';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/templates/:templateId')
            ->desc('Get site template')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'getTemplate')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.description', '/docs/references/sites/get-template.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_TEMPLATE_SITE)
            ->param('templateId', '', new Text(128), 'Template ID.')
            ->inject('response')
            ->callback([$this, 'action']);
    }

    public function action(string $templateId, Response $response)
    {
        $templates = Config::getParam('site-templates', []);

        $template = array_shift(\array_filter($templates, function ($template) use ($templateId) {
            return $template['id'] === $templateId;
        }));

        if (empty($template)) {
            throw new Exception(Exception::SITE_TEMPLATE_NOT_FOUND);
        }

        $response->dynamic(new Document($template), Response::MODEL_TEMPLATE_SITE);
    }
}
