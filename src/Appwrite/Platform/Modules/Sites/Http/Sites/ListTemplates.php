<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

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

class ListTemplates extends Base
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
            ->setHttpPath('/v1/sites/templates')
            ->desc('List templates')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'listTemplates',
                description: '/docs/references/sites/list-templates.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEMPLATE_SITE_LIST,
                    )
                ]
            ))
            ->param('frameworks', [], new ArrayList(new WhiteList(array_keys(Config::getParam('frameworks')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of frameworks allowed for filtering site templates. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' frameworks are allowed.', true)
            ->param('useCases', [], new ArrayList(new WhiteList(['dev-tools', 'starter', 'databases', 'ai', 'messaging', 'utilities']), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of use cases allowed for filtering site templates. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' use cases are allowed.', true)
            ->param('limit', 25, new Range(1, 5000), 'Limit the number of templates returned in the response. Default limit is 25, and maximum limit is 5000.', true)
            ->param('offset', 0, new Range(0, 5000), 'Offset the list of returned templates. Maximum offset is 5000.', true)
            ->inject('response')
            ->callback([$this, 'action']);
    }

    public function action(array $frameworks, array $usecases, int $limit, int $offset, Response $response)
    {
        $templates = Config::getParam('site-templates', []);

        if (!empty($frameworks)) {
            $templates = \array_filter($templates, function ($template) use ($frameworks) {
                return \count(\array_intersect($frameworks, \array_column($template['frameworks'], 'name'))) > 0;
            });
        }

        if (!empty($usecases)) {
            $templates = \array_filter($templates, function ($template) use ($usecases) {
                return \count(\array_intersect($usecases, $template['useCases'])) > 0;
            });
        }

        $responseTemplates = \array_slice($templates, $offset, $limit);
        $response->dynamic(new Document([
            'templates' => $responseTemplates,
            'total' => \count($responseTemplates),
        ]), Response::MODEL_TEMPLATE_SITE_LIST);
    }
}
