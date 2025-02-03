<?php

namespace Appwrite\Platform\Modules\Sites\Http\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class GetVariable extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/variables/:variableId')
            ->desc('Get variable')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.read')
            ->label(
                'sdk',
                new Method(
                    namespace: 'functions',
                    name: 'getVariable',
                    description: '/docs/references/functions/get-variable.md',
                    auth: [AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_VARIABLE,
                        )
                    ],
                )
            )
            ->param('siteId', '', new UID(), 'Site unique ID.', false)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $variableId, Response $response, Database $dbForProject)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if (
            $variable === false ||
            $variable->isEmpty() ||
            $variable->getAttribute('resourceInternalId') !== $site->getInternalId() ||
            $variable->getAttribute('resourceType') !== 'site'
        ) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
