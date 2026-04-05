<?php

namespace Appwrite\Platform\Modules\Webhooks\Http\Webhooks;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Validator\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Multiple;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/webhooks')
            ->httpAlias('/v1/projects/:projectId/webhooks')
            ->desc('Create webhook')
            ->groups(['api', 'webhooks'])
            ->label('scope', 'webhooks.write')
            ->label('event', 'webhooks.[webhookId].create')
            ->label('audits.event', 'webhook.create')
            ->label('audits.resource', 'webhook/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'webhooks',
                group: null,
                name: 'create',
                description: <<<EOT
                Create a new webhook. Use this endpoint to configure a URL that will receive events from Appwrite when specific events occur.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_WEBHOOK,
                    )
                ],
            ))
            ->param('webhookId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Webhook ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('url', '', fn () => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'Webhook URL.')
            ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
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

    /**
     * @param array<string> $events
     */
    public function action(
        string $webhookId,
        string $url,
        string $name,
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
        $webhookId = ($webhookId == 'unique()') ? ID::unique() : $webhookId;

        $webhook = new Document([
            '$id' => $webhookId,
            '$permissions' => [],
            'projectInternalId' => $project->getSequence(),
            'projectId' => $project->getId(),
            'name' => $name,
            'events' => $events,
            'url' => $url,
            'security' => $security,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
            'signatureKey' => \bin2hex(\random_bytes(64)),
            'enabled' => $enabled,
        ]);

        try {
            $webhook = $authorization->skip(fn () => $dbForPlatform->createDocument('webhooks', $webhook));
        } catch (DuplicateException) {
            throw new Exception(Exception::WEBHOOK_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('webhookId', $webhook->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($webhook, Response::MODEL_WEBHOOK);
    }
}
