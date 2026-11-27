<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId')
            ->desc('Delete app')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].delete')
            ->label('audits.event', 'distribution.app.delete')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'apps',
                name: 'deleteApp',
                description: <<<EOT
                Delete an app by its unique ID. Its targets and variables are deleted with it.
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
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('distributionApps', $app->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove app from DB');
        }

        $dbForProject->deleteDocuments('distributionTargets', [
            Query::equal('appInternalId', [$app->getSequence()]),
        ]);

        $dbForProject->deleteDocuments('variables', [
            Query::equal('resourceType', [RESOURCE_TYPE_DISTRIBUTION_APPS]),
            Query::equal('resourceInternalId', [$app->getSequence()]),
        ]);

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_DOCUMENT,
            document: $app,
        ));

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setPayload($response->output($app, Response::MODEL_DISTRIBUTION_APP));

        $response->noContent();
    }
}
