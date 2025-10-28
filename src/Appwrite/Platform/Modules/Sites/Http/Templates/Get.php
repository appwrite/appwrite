<?php

namespace Appwrite\Platform\Modules\Sites\Http\Templates;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Base
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
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'templates',
                name: 'getTemplate',
                description: <<<EOT
                Get a site template using ID. You can use template details in [createSite](/docs/references/cloud/server-nodejs/sites#create) method.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEMPLATE_SITE,
                    )
                ]
            ))
            ->param('templateId', '', new Text(128), 'Template ID.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $templateId, Response $response)
    {
        $templates = Config::getParam('templates-site', []);

        $allowedTemplates = \array_filter($templates, function ($item) use ($templateId) {
            return $item['key'] === $templateId;
        });
        $template = array_shift($allowedTemplates);

        if (empty($template)) {
            throw new Exception(Exception::SITE_TEMPLATE_NOT_FOUND);
        }

        $response->dynamic(new Document($template), Response::MODEL_TEMPLATE_SITE);
    }
}
