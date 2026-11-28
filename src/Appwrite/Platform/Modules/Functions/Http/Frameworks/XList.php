<?php

namespace Appwrite\Platform\Modules\Functions\Http\Frameworks;

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
use Utopia\Validator\Boolean;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listFunctionFrameworks';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/frameworks')
            ->desc('List frameworks')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'frameworks',
                name: 'listFrameworks',
                description: <<<EOT
                List the web frameworks functions can run on this server, such as Express, with the runtimes each one supports.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FUNCTION_FRAMEWORK_LIST,
                    )
                ]
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(bool $includeTotal, Response $response)
    {
        $allowList = \array_filter(\array_map('trim', \explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES', ''))));

        $frameworks = [];
        foreach (Config::getParam('functionFrameworks', []) as $framework) {
            if (!empty($allowList)) {
                $framework['runtimes'] = \array_values(\array_filter(
                    $framework['runtimes'],
                    fn (string $runtime): bool => \in_array($runtime, $allowList, true)
                ));

                // A framework none of whose runtimes are enabled can't be picked.
                if (empty($framework['runtimes'])) {
                    continue;
                }
            }

            $frameworks[] = $framework;
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? \count($frameworks) : 0,
            'frameworks' => $frameworks,
        ]), Response::MODEL_FUNCTION_FRAMEWORK_LIST);
    }
}
