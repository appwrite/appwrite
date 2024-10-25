<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Platform\Modules\Compute\Base;
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
            ->label('scope', 'functions.read') // TODO: Update scope to sites.read
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'listFrameworks')
            ->label('sdk.description', '/docs/references/sites/list-frameworks.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_FRAMEWORK_LIST)
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

            $framework['$id'] = $id;
            $allowed[] = $framework;
        }

        $response->dynamic(new Document([
            'total' => count($allowed),
            'frameworks' => $allowed
        ]), Response::MODEL_FRAMEWORK_LIST);
    }
}
