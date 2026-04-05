<?php

namespace Appwrite\Platform\Modules\Webhooks\Http\Webhooks;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/webhooks/:webhookId')
            ->httpAlias('/v1/projects/:projectId/webhooks/:webhookId')
            ->desc('Delete webhook')
            ->groups(['api', 'webhooks'])
            ->label('scope', 'webhooks.write')
            ->label('event', 'webhooks.[webhookId].delete')
            ->label('audits.event', 'webhook.delete')
            ->label('audits.resource', 'webhook/{request.webhookId}')
            ->label('sdk', new Method(
                namespace: 'webhooks',
                group: null,
                name: 'delete',
                description: <<<EOT
                Delete a webhook by its unique ID. Once deleted, the webhook will no longer receive project events. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('webhookId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Webhook ID.', false, ['dbForPlatform'])
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $webhookId,
        Document $project,
        Response $response,
        Database $dbForPlatform,
        Event $queueForEvents,
        Authorization $authorization
    ) {
        $webhook = $authorization->skip(fn () => $dbForPlatform->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]));

        if ($webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('webhooks', $webhook->getId()))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove document from DB');
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('webhookId', $webhook->getId());

        $response->noContent();
    }
}
