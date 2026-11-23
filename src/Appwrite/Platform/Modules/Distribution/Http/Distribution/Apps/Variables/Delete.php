<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId/variables/:variableId')
            ->desc('Delete variable')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'variables.[variableId].delete')
            ->label('audits.event', 'distribution.variable.delete')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'variables',
                name: 'deleteVariable',
                description: <<<EOT
                Delete an app variable by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App unique ID.', false, ['dbForProject'])
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Variable unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $variableId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
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

        if (!$dbForProject->deleteDocument('variables', $variable->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove variable from DB');
        }

        $queueForEvents
            ->setParam('variableId', $variable->getId())
            ->setPayload($response->output($variable, Response::MODEL_VARIABLE));

        $response->noContent();
    }
}
