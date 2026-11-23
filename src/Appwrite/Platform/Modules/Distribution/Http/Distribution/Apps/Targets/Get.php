<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets;

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
        return 'getTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/apps/:appId/targets/:targetId')
            ->desc('Get target')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.read')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'targets',
                name: 'getTarget',
                description: <<<EOT
                Get a target of an app by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_TARGET,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $targetId,
        Response $response,
        Database $dbForProject
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('distributionTargets', $targetId);

        if ($target->isEmpty() || $target->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_TARGET_NOT_FOUND);
        }

        $response->dynamic($target, Response::MODEL_DISTRIBUTION_TARGET);
    }
}
