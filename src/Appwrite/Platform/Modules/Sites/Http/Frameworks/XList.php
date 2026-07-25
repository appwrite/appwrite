<?php

namespace Appwrite\Platform\Modules\Sites\Http\Frameworks;

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
        return 'listFrameworks';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/frameworks')
            ->desc('List frameworks')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'frameworks',
                name: 'listFrameworks',
                description: <<<EOT
                Get a list of all frameworks that are currently available on the server instance.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FRAMEWORK_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response)
    {
        $frameworks = Config::getParam('frameworks');
        $allowList = \array_filter(\array_map('trim', \explode(',', System::getEnv('_APP_SITES_RUNTIMES', ''))));

        foreach ($frameworks as $key => $framework) {
            if (!empty($framework['adapters'])) {
                $frameworks[$key]['adapters'] = \array_values($framework['adapters']);
            }

            if (!empty($allowList) && !empty($framework['runtimes'])) {
                $frameworks[$key]['runtimes'] = \array_values(\array_filter(
                    $framework['runtimes'],
                    fn ($runtime) => \in_array($runtime, $allowList, true)
                ));

                // Drop frameworks that have no enabled build runtimes left.
                if (empty($frameworks[$key]['runtimes'])) {
                    unset($frameworks[$key]);
                    continue;
                }

                // Keep the default buildRuntime aligned with the filtered allowlist.
                if (!\in_array($frameworks[$key]['buildRuntime'] ?? '', $frameworks[$key]['runtimes'], true)) {
                    $frameworks[$key]['buildRuntime'] = $frameworks[$key]['runtimes'][0];
                }
            }
        }

        $response->dynamic(new Document([
            'total' => count($frameworks),
            'frameworks' => \array_values($frameworks)
        ]), Response::MODEL_FRAMEWORK_LIST);
    }
}
