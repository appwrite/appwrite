<?php

namespace Appwrite\Platform\Modules\Functions\Http\Templates;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listTemplates';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/templates')
            ->desc('List templates')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                name: 'listTemplates',
                description: <<<EOT
                List available function templates. You can use template details in [createFunction](/docs/references/cloud/server-nodejs/functions#create) method.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEMPLATE_FUNCTION_LIST,
                    )
                ]
            ))
            ->param('runtimes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('runtimes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of runtimes allowed for filtering function templates. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' runtimes are allowed.', true)
            ->param('useCases', [], new ArrayList(new WhiteList(['dev-tools','starter','databases','ai','messaging','utilities']), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of use cases allowed for filtering function templates. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' use cases are allowed.', true)
            ->param('limit', 25, new Range(1, 5000), 'Limit the number of templates returned in the response. Default limit is 25, and maximum limit is 5000.', true)
            ->param('offset', 0, new Range(0, 5000), 'Offset the list of returned templates. Maximum offset is 5000.', true)
            ->inject('response')
            ->callback([$this, 'action']);
    }

    public function action(array $runtimes, array $usecases, int $limit, int $offset, Response $response)
    {
        $templates = Config::getParam('templates-function', []);

        if (!empty($runtimes)) {
            $templates = \array_filter($templates, function ($template) use ($runtimes) {
                return \count(\array_intersect($runtimes, \array_column($template['runtimes'], 'name'))) > 0;
            });
        }

        if (!empty($usecases)) {
            $templates = \array_filter($templates, function ($template) use ($usecases) {
                return \count(\array_intersect($usecases, $template['useCases'])) > 0;
            });
        }

        \usort($templates, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $templates = \array_slice($templates, $offset, $limit);
        $response->dynamic(new Document([
            'templates' => $templates,
            'total' => \count($templates),
        ]), Response::MODEL_TEMPLATE_FUNCTION_LIST);
    }
}
