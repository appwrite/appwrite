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
use Utopia\System\System;

class ListFrameworks extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listFrameworks';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/frameworks')
            ->desc('List frameworks')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.read')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'listFrameworks',
                description: '/docs/references/sites/list-frameworks.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FRAMEWORK_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->callback([$this, 'action']);
    }

    public function action(Response $response)
    {
        $frameworks = Config::getParam('frameworks');

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_SITES_FRAMEWORKS', '')));

        $allowed = [];
        foreach ($frameworks as $id => $framework) {
            if (!empty($allowList) && !\in_array($id, $allowList)) {
                continue;
            }

            $allowed[] = $framework;
        }

        $response->dynamic(new Document([
            'total' => count($allowed),
            'frameworks' => $allowed
        ]), Response::MODEL_FRAMEWORK_LIST);
    }
}
