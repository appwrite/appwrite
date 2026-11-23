<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/apps/:appId/variables/:variableId')
            ->desc('Get variable')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.read')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'variables',
                name: 'getVariable',
                description: <<<EOT
                Get an app variable by its unique ID. The value of a secret variable is hidden.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VARIABLE,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App unique ID.', false, ['dbForProject'])
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Variable unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $variableId,
        Response $response,
        Database $dbForProject
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);

        if (
            $variable->isEmpty()
            || $variable->getAttribute('resourceInternalId') !== $app->getSequence()
            || $variable->getAttribute('resourceType') !== RESOURCE_TYPE_DISTRIBUTION_APPS
        ) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
