<?php

namespace Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Signature;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
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

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateWebhookSignature';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/webhooks/:webhookId/signature')
            ->httpAlias('/v1/projects/:projectId/webhooks/:webhookId/signature')
            ->desc('Update webhook signature key')
            ->groups(['api', 'webhooks'])
            ->label('scope', 'webhooks.write')
            ->label('event', 'webhooks.[webhookId].update')
            ->label('audits.event', 'webhooks.update')
            ->label('audits.resource', 'webhook/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'webhooks',
                group: null,
                name: 'updateSignature',
                description: <<<EOT
                Update the webhook signature key. This endpoint can be used to regenerate the signature key used to sign and validate payload deliveries for a specific webhook.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_WEBHOOK,
                    )
                ]
            ))
            ->param('webhookId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Webhook ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $webhookId,
        Response $response,
        Document $project,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization
    ) {
        $webhook = $authorization->skip(fn () => $dbForPlatform->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]));

        if ($webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $updates = new Document([
            'signatureKey' => \bin2hex(\random_bytes(64)),
        ]);

        $webhook = $authorization->skip(fn () => $dbForPlatform->updateDocument('webhooks', $webhook->getId(), $updates));

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('webhookId', $webhook->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    }
}
