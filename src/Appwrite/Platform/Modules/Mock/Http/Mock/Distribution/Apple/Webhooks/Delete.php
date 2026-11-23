<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Webhooks;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteDistributionAppleWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/webhooks/:webhookId')
            ->desc('Delete App Store Connect webhook')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('webhookId', '', new Text(64), 'Webhook ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $webhookId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $webhook = $this->webhook($cache, $webhookId);

        if ($webhook === null || !$this->owns($cache, $key['issuerId'], $webhook['appId'])) {
            $this->notFound($response, 'webhooks', $webhookId);
            return;
        }

        $this->deleteWebhook($cache, $lock, $webhook);

        $response->noContent();
    }
}
