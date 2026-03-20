<?php

namespace Appwrite\Platform\Modules\Functions\Http\Runtimes;

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

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listRuntimes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/runtimes')
            ->groups(['api'])
            ->desc('List runtimes')
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'runtimes',
                name: 'listRuntimes',
                description: <<<EOT
                Get a list of all runtimes that are currently active on your instance.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_RUNTIME_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response)
    {
        $runtimes = Config::getParam('runtimes');

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES', '')));

        $allowed = [];
        foreach ($runtimes as $id => $runtime) {
            if (!empty($allowList) && !\in_array($id, $allowList)) {
                continue;
            }

            $runtime['$id'] = $id;
            $allowed[] = $runtime;
        }

        $response->dynamic(new Document([
            'total' => count($allowed),
            'runtimes' => $allowed
        ]), Response::MODEL_RUNTIME_LIST);
    }
}
