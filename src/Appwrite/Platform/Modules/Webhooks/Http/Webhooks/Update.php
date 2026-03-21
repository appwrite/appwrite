<?php

namespace Appwrite\Platform\Modules\Webhooks\Http\Webhooks;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Validator\Event;
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
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Multiple;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateWebhook';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/webhooks/:webhookId')
            ->httpAlias('/v1/projects/:projectId/webhooks/:webhookId')
            ->desc('Update webhook')
            ->groups(['api', 'webhooks'])
            ->label('scope', 'webhooks.write')
            ->label('event', 'webhooks.[webhookId].update')
            ->label('audits.event', 'webhooks.update')
            ->label('audits.resource', 'webhook/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'webhooks',
                group: null,
                name: 'update',
                description: <<<EOT
                Update a webhook by its unique ID. Use this endpoint to update the URL, events, or status of an existing webhook.
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
            ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
            ->param('url', '', fn () => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'Webhook URL.')
            ->param('events', null, new ArrayList(new Event(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
            ->param('enabled', true, new Boolean(), 'Enable or disable a webhook.', true)
            ->param('security', false, new Boolean(), 'Certificate verification, false for disabled or true for enabled.', true)
            ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
            ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $webhookId,
        string $name,
        string $url,
        array $events,
        bool $enabled,
        bool $security,
        string $httpUser,
        string $httpPass,
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
            'name' => $name,
            'events' => $events,
            'url' => $url,
            'security' => $security,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
            'enabled' => $enabled,
        ]);

        if ($enabled) {
            $updates->setAttribute('attempts', 0);
        }

        $webhook = $authorization->skip(fn () => $dbForPlatform->updateDocument('webhooks', $webhook->getId(), $updates));

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('webhookId', $webhook->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    }
}
