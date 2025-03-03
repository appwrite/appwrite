<?php

namespace Appwrite\Platform\Modules\Functions\Http\Specifications;

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
        return 'listFunctionsSpecifications';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/specifications')
            ->groups(['api', 'functions'])
            ->desc('List available function runtime specifications')
            ->label('scope', 'functions.read')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                name: 'listSpecifications',
                description: <<<EOT
                List allowed function specifications for this instance.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SPECIFICATION_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('plan')
            ->callback([$this, 'action']);
    }

    public function action(Response $response, array $plan)
    {
        $allRuntimeSpecs = Config::getParam('runtime-specifications', []);

        $runtimeSpecs = [];
        foreach ($allRuntimeSpecs as $spec) {
            $spec['enabled'] = true;

            if (array_key_exists('runtimeSpecifications', $plan)) {
                $spec['enabled'] = in_array($spec['slug'], $plan['runtimeSpecifications']);
            }

            // Only add specs that are within the limits set by environment variables
            if ($spec['cpus'] <= System::getEnv('_APP_COMPUTE_CPUS', 1) && $spec['memory'] <= System::getEnv('_APP_COMPUTE_MEMORY', 512)) {
                $runtimeSpecs[] = $spec;
            }
        }

        $response->dynamic(new Document([
            'specifications' => $runtimeSpecs,
            'total' => count($runtimeSpecs)
        ]), Response::MODEL_SPECIFICATION_LIST);
    }
}
