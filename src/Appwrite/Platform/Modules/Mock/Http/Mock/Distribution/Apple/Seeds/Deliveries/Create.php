<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Deliveries;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Create extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'version', 'attributes', 'relationships'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleSeedDelivery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/deliveries')
            ->desc('Seed App Store Connect webhook delivery')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('webhookId', '', new Text(64), 'ID of the webhook to deliver to, signed with its secret.')
            ->param('eventType', '', new Text(128), 'Event type the delivery is recorded under.')
            ->param('data', [], new Assoc(), 'Event resource sent as the body `data`, verbatim.')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $webhookId, string $eventType, array $data, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $webhook = $this->webhook($cache, $webhookId);

        if ($webhook === null) {
            throw new Exception(Exception::GENERAL_MOCK, 'Webhook not found: ' . $webhookId);
        }

        $data = \array_intersect_key($data, \array_flip(self::ALLOWED_KEYS['data']));
        $delivery = $this->send($cache, $lock, $clientForWebhooks, $webhook, $eventType, ['data' => $data]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($delivery);
    }
}
