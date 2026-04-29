<?php

namespace Appwrite\Platform\Modules\Functions\Http\Templates;

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
            ->setHttpPath('/v1/functions/templates/:templateId')
            ->desc('Get function template')
            ->groups(['api', 'functions'])
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'templates',
                name: 'getTemplate',
                description: <<<EOT
                Get a function template using ID. You can use template details in [createFunction](/docs/references/cloud/server-nodejs/functions#create) method.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEMPLATE_FUNCTION,
                    )
                ]
            ))
            ->param('templateId', '', new Text(128), 'Template ID.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $templateId, Response $response)
    {
        $templates = Config::getParam('templates-function', []);

        $filtered = \array_filter($templates, function ($template) use ($templateId) {
            return $template['id'] === $templateId;
        });

        $template = array_shift($filtered);

        if (empty($template)) {
            throw new Exception(Exception::FUNCTION_TEMPLATE_NOT_FOUND);
        }

        $response->dynamic(new Document($template), Response::MODEL_TEMPLATE_FUNCTION);
    }
}
