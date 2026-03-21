<?php

namespace Appwrite\Platform\Modules\Webhooks\Http\Webhooks;

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

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/webhooks/:webhookId')
            ->httpAlias('/v1/projects/:projectId/webhooks/:webhookId')
            ->desc('Get webhook')
            ->groups(['api', 'webhooks'])
            ->label('scope', 'webhooks.read')
            ->label('sdk', new Method(
                namespace: 'webhooks',
                group: null,
                name: 'get',
                description: <<<EOT
                Get a webhook by its unique ID. This endpoint returns details about a specific webhook configured for a project. 
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
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $webhookId,
        Document $project,
        Response $response,
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

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    }
}
