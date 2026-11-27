<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\WebhookPings;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const string TYPE = 'webhookPings';
    private const string EVENT_TYPE = 'WEBHOOK_PING_CREATED';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'relationships'],
        'relationships' => ['webhook'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleWebhookPing';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/webhookPings')
            ->desc('Create App Store Connect webhook ping')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Webhook ping resource with a `webhook` relationship.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $data = \array_intersect_key($data, \array_flip(self::ALLOWED_KEYS['data']));
        $relationships = \is_array($data['relationships'] ?? null) ? \array_intersect_key($data['relationships'], \array_flip(self::ALLOWED_KEYS['relationships'])) : [];

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->error($response, Response::STATUS_CODE_CONFLICT, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", ['pointer' => '/data/type']);
            return;
        }

        $webhookId = $relationships['webhook']['data']['id'] ?? null;

        if (!\is_string($webhookId) || $webhookId === '' || ($relationships['webhook']['data']['type'] ?? null) !== 'webhooks') {
            $this->error($response, Response::STATUS_CODE_CONFLICT, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'webhook' with this request", ['pointer' => '/data/relationships/webhook']);
            return;
        }

        $webhook = $this->webhook($cache, $webhookId);

        if ($webhook === null || !$this->owns($cache, $key['issuerId'], $webhook['appId'])) {
            $this->error($response, Response::STATUS_CODE_CONFLICT, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The webhook '{$webhookId}' does not exist.", ['pointer' => '/data/relationships/webhook']);
            return;
        }

        $ping = ['type' => self::TYPE, 'id' => $this->uuid()];

        $this->post($cache, $lock, $clientForWebhooks, $webhook, self::EVENT_TYPE, [
            'attributes' => [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
            'relationships' => [
                'instance' => ['data' => $ping],
            ],
        ]);

        $this->respond($response, [
            'data' => $ping,
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
