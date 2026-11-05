<?php

namespace Appwrite\Platform\Modules\Functions\Http\Specifications;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listSpecifications';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/specifications')
            ->groups(['api', 'functions'])
            ->desc('List specifications')
            ->label('scope', 'functions.read')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'runtimes',
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
            ->param('type', 'runtimes', new WhiteList(['runtimes', 'builds']), 'Specification type to list. Can be one of: runtimes, builds. Defaults to runtimes.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('plan')
            ->callback($this->action(...));
    }

    public function action(string $type, bool $includeTotal, Response $response, array $plan)
    {
        $specs = $this->getPlanSpecifications($plan, $type === 'builds' ? 'buildSpecifications' : 'runtimeSpecifications');

        $response->dynamic(new Document([
            'specifications' => $specs,
            'total' => $includeTotal ? count($specs) : 0
        ]), Response::MODEL_SPECIFICATION_LIST);
    }
}
